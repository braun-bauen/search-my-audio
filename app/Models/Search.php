<?php

namespace App\Models;

use App\Enums\FileStatus;
use App\Enums\SearchStatus;
use App\Jobs\BatchUpload;
use App\Jobs\CreateReport;
use App\Mail\SearchFinished;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class Search extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = ['query', 'user_id', 'status', 'completion_email'];

    protected $casts = [
        'status' => SearchStatus::class,
    ];

    /**
     * Get the uploaded files for the search
     */
    public function files(): HasMany
    {
        return $this->hasMany(AudioFile::class);
    }

    /**
     * Get the user that owns the search
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return the search's creation date formatted and in the user's timezone
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        return $this->created_at->setTimezone(Auth::user()->timezone ?? 'UTC')->toDayDateTimeString();
    }

    /**
     * Creates a new search record with associated files and dispatches a batch upload job.
     *
     * Throws an exception if no files are provided. Returns the ID of the created search.
     *
     * @param  array  $searchData  Associative array with keys: user_id, query, and completion_email.
     * @param  array  $fileArray  Array of [name, path] pairs representing files to associate.
     * @return int The ID of the newly created search.
     *
     * @throws Exception If no files are provided or creation fails.
     */
    public static function createWithFiles(array $searchData, array $fileArray): int
    {
        if (empty($fileArray)) {
            throw new Exception('No files provided');
        }

        try {
            // Create the search entry
            $search = static::create([
                'user_id' => $searchData['user_id'],
                'query' => $searchData['query'],
                'completion_email' => $searchData['completion_email'],
            ]);

            BatchUpload::dispatch($search, $fileArray, Auth::user()->timezone ?? 'UTC');

            return $search->id;
        } catch (Exception $e) {
            Log::error('Search creation failed', [
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }

    /**
     * Finalizes the search if all associated files are processed.
     *
     * If no files remain in the 'Queued' or 'Uploaded' status, marks the search as completed, saves it, and sends a completion email. If not a retry and matches were found, dispatches a report creation job before completing.
     *
     * @param  bool  $retry  Indicates if this is a retry attempt.
     */
    public function attemptToFinish(bool $retry): void
    {
        // Check if there are NO files with a status NOT equal to the desired status
        $allFilesProcessed = $this->files()
            ->whereIn('status', [FileStatus::Queued, FileStatus::Uploaded])
            ->doesntExist();

        // If all files have NOT been processed, exit
        if (! $allFilesProcessed) {
            return;
        }

        // Create Report if matches found
        if (! $retry && $this->query_total > 0) {
            CreateReport::dispatch($this);
        }

        $this->status = SearchStatus::Completed;
        $this->save();

    }

    /**
     * Increments the query total for the search by the specified count.
     *
     * If the query total is not set, it initializes it with the given count.
     *
     * @param  int  $count  The number to add to the query total.
     */
    public function addToQueryCount(int $count): void
    {
        // Use database-level atomic operation to prevent race conditions
        $updated = $this->newQuery()
            ->where('id', $this->id)
            ->whereNotNull('query_total')
            ->increment('query_total', $count);

        if ($updated === 0) {
            // query_total was null, so initialize it
            $this->newQuery()
                ->where('id', $this->id)
                ->whereNull('query_total')
                ->update(['query_total' => $count]);
        }

        // Refresh the model to get the updated value
        $this->refresh();
        $this->save();
    }

    /**
     * Send email to user if requested
     */
    public function sendEmail(): void
    {
        // Send user email of completion
        if ($this->completion_email) {
            Mail::to($this->user)->send(new SearchFinished($this));
        }
    }
}
