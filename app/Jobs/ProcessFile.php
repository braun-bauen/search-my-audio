<?php

namespace App\Jobs;

use App\Enums\FileStatus;
use App\Enums\SearchStatus;
use App\Models\AudioFile;
use App\Models\Search;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessFile implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 720;

    /****
     * Initializes the ProcessFile job with the given search and audio file models.
     *
     * @param Search $search The search operation associated with this job.
     * @param AudioFile $file The audio file to be processed.
     * @param bool $retry Indicates if this is a retry attempt.
     */
    public function __construct(
        public Search $search,
        public AudioFile $file,
        public bool $retry = false,
    ) {
        $this->onQueue('transcription');
    }

    /****
     * Processes an audio file for transcription and search as a queued job.
     *
     * Checks for batch cancellation, updates the search and file statuses, verifies audio file existence, transcribes the audio, searches for query matches, stores the results, and updates related models. On failure, marks the file as failed and rethrows the exception.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            // Determine if the batch has been cancelled...
            return;
        }

        // Update Search Status if necessary
        if ($this->search->status !== SearchStatus::Processing) {
            $this->search->status = SearchStatus::Processing;
            $this->search->save();
        }

        // If audio file doesn't exist
        if (! $this->file->audio_path || ! Storage::exists($this->file->audio_path)) {
            throw new \Exception('Audio file does not exist');
        }

        // Process File
        try {
            [$transcription_path, $match_count] = $this->processAndStore();

            // Update File Model
            $this->file->query_count = $match_count;
            $this->file->transcription_path = $transcription_path;
            $this->file->status = FileStatus::Transcribed;
            $this->file->save();

            // Update query total
            $this->search->addToQueryCount($match_count);

            // Call Finished Check
            $this->search->attemptToFinish($this->retry);
        } catch (\Exception $e) {
            $this->file->status = FileStatus::Failed;
            $this->file->save();
            // Provider/transport exceptions may contain bodies, URLs, or credentials.
            throw new \RuntimeException('Error processing file');
        }
    }

    /**
     * Handles cleanup and error reporting when the job fails.
     *
     * Sets the audio file status to failed and saves it. Reports the exception to Sentry if available; otherwise logs a warning.
     *
     * @param  \Throwable  $exception  The exception that caused the job to fail.
     */
    public function failed(\Throwable $exception)
    {
        $this->file->status = FileStatus::Failed;
        $this->file->save();

        if (app()->bound('sentry')) {
            app('sentry')->captureException($exception);
        } else {
            Log::warning('Sentry not bound');
        }
    }

    /**
     * Transcribes the audio file, searches for query matches, and stores the results as a JSON file.
     *
     * @return array An array containing the transcription file path and the number of query matches found.
     *
     * @throws \Exception If the match count is missing from the search results or if writing to storage fails.
     */
    protected function processAndStore(): array
    {
        // Get transcription response from Whisper
        $transcription_response = $this->transcribeWithWhisper($this->file->audio_path);

        // Search for query and format
        $matches_json = $this->findAndSegment($transcription_response, $this->search->query);

        // Check if the matchCount key exists
        if (! isset($matches_json['matchCount'])) {
            throw new \Exception('matchCount not found in JSON response');
        }

        // Store search response to file
        $transcription_path = 'transcriptions/'.Str::uuid()->toString().'.json';

        try {
            Storage::put($transcription_path, json_encode($matches_json));

            return [$transcription_path, $matches_json['matchCount']];
        } catch (\Exception $e) {
            // Let the caller decide how to mark failure
            throw new \Exception(
                'Error writing transcription to storage: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    /****
     * Sends an audio file to OpenAI's Whisper API and returns the transcribed text.
     *
     * @param string $audio_path Path to the audio file in storage.
     * @return string The transcribed text from the audio file.
     * @throws \Exception If the Whisper API request fails or returns an error.
     */
    protected function transcribeWithWhisper(string $audio_path): string
    {
        // Get a stream resource for the file
        $stream = Storage::readStream($audio_path);

        // Get the original filename or create one
        $filename = basename($audio_path);

        $started = hrtime(true);
        $status = null;

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->connectTimeout(10)
                ->timeout(600)
                ->attach(
                    'file',
                    $stream,
                    $filename
                )
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'language' => 'en',
                    'response_format' => 'json',
                ]);

            $status = $response->status();

            // Close the stream
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $response->successful()) {
                throw new \Exception('Whisper API request failed (HTTP '.$response->status().')');
            }

            $result = $response->json();

            return $result['text'] ?? '';
        } catch (\Exception $e) {
            // Make sure we clean up even if there's an error
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException('Transcription request failed');
        } finally {
            Log::info('pipeline_provider', [
                'search_id' => $this->search->id,
                'file_id' => $this->file->id,
                'duration_ms' => round((hrtime(true) - $started) / 1e6),
                'http_status' => $status,
            ]);
        }
    }

    /**
     * Find all occurrences of a search string in text and segment the text.
     *
     * @param  string  $text  The text to search within
     * @param  string  $searchString  The string to search for
     * @return array The result with match count and segments
     */
    protected function findAndSegment(string $text, string $searchString): array
    {
        $result = [
            'matchCount' => 0,
            'segments' => [],
            'fullText' => $text,
        ];

        if (empty($searchString) || empty($text)) {
            $result['segments'][] = [
                'match' => false,
                'text' => $text,
            ];

            return $result;
        }

        $currentPosition = 0;
        $searchLen = strlen($searchString);
        $textLen = strlen($text);

        while ($currentPosition <= $textLen) {
            // Find the next occurrence of the search string
            $nextMatchPosition = stripos($text, $searchString, $currentPosition); // Using case-insensitive search

            // If no more matches are found
            if ($nextMatchPosition === false) {
                // Add the remaining text as a non-matching segment
                if ($currentPosition < $textLen) {
                    $result['segments'][] = [
                        'match' => false,
                        'text' => substr($text, $currentPosition),
                    ];
                }
                break;
            }

            // Add the text before the match as a non-matching segment
            if ($nextMatchPosition > $currentPosition) {
                $result['segments'][] = [
                    'match' => false,
                    'text' => substr($text, $currentPosition, $nextMatchPosition - $currentPosition),
                ];
            }

            // Add the matching segment
            $result['segments'][] = [
                'match' => true,
                'text' => substr($text, $nextMatchPosition, $searchLen),
            ];

            // Increment the match count
            $result['matchCount']++;

            // Move the current position past this match
            $currentPosition = $nextMatchPosition + $searchLen;
        }

        return $result;
    }
}
