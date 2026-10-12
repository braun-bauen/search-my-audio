<?php

use App\Enums\FileStatus;
use App\Enums\SearchStatus;
use App\Jobs\BatchUpload;
use App\Jobs\CreateReport;
use App\Jobs\ProcessFile;
use App\Jobs\UploadFile;
use App\Models\AudioFile;
use App\Models\Search;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

function historicalSearch(User $owner): array
{
    Storage::fake('local');
    config(['filesystems.default' => 'local']);
    $search = Search::create(['user_id' => $owner->id, 'query' => 'operator phrase', 'completion_email' => true]);
    $search->update(['status' => SearchStatus::Completed]);
    $file = AudioFile::create(['search_id' => $search->id, 'audio_path' => 'audioFiles/owned.mp3',
        'audio_filename' => 'owned.mp3', 'status' => FileStatus::Transcribed, 'query_count' => 1]);
    $file->transcription_path = 'transcriptions/historical.json';
    $file->save();
    $search->report_path = 'reports/historical.csv';
    $search->save();
    Storage::put($file->audio_path, 'operator-controlled test bytes');
    Storage::put($file->transcription_path, '{"fullText":"operator phrase","matchCount":1,"segments":[{"match":true,"text":"operator phrase"}]}');
    Storage::put($search->report_path, "File,Matches\nowned.mp3,1\n");

    return [$search, $file];
}

it('preserves owned historical results, transcript bytes and report access while denying another user', function () {
    $owner = User::factory()->create();
    [$search, $file] = historicalSearch($owner);
    $file->refresh();
    $before = $file->getRawOriginal();
    $hash = hash('sha256', Storage::get($file->transcription_path));

    $this->actingAs($owner)->get(route('results', $search->id))->assertOk()->assertSee('operator phrase');
    Volt::actingAs($owner)->test('results', ['id' => $search->id])->call('downloadReport')->assertFileDownloaded('audio-search-report-'.$search->id.'.csv');
    expect($file->getTranscription()['matchCount'])->toBe(1)
        ->and($file->fresh()->getRawOriginal())->toBe($before)
        ->and(hash('sha256', Storage::get($file->transcription_path)))->toBe($hash)
        ->and(Storage::exists($file->audio_path))->toBeTrue();
    $this->actingAs(User::factory()->create())->get(route('results', $search->id))->assertForbidden();
});

it('keeps ingest batches separate from standalone transcription and report work', function () {
    $owner = User::factory()->create();
    [$search] = historicalSearch($owner);
    Queue::fake();
    (new UploadFile($search, ['name' => 'owned.mp3'], 'UTC', 1))->createAndQueue('audioFiles/owned.mp3');
    Queue::assertPushedOn('transcription', ProcessFile::class);
    Queue::assertPushed(ProcessFile::class, fn ($job) => $job->batchId === null && $job->timeout === 720);
    expect((new BatchUpload($search, [['name' => 'owned.mp3', 'path' => 'owned.mp3']], 'UTC'))->queue)->toBe('ingest')
        ->and((new CreateReport($search))->queue)->toBe('lightweight');
    Bus::fake();
    (new BatchUpload($search, [['name' => 'owned.mp3', 'path' => 'owned.mp3']], 'UTC'))->handle();
    Bus::assertBatched(fn ($batch) => ($batch->options['queue'] ?? null) === 'ingest' && $batch->jobs->every(fn ($job) => $job instanceof UploadFile));
});

it('runs an existing batched default-queue payload and routes its report independently', function () {
    $owner = User::factory()->create();
    [$search, $file] = historicalSearch($owner);
    $file->status = FileStatus::Uploaded;
    $file->transcription_path = null;
    $file->save();
    config(['queue.default' => 'database', 'services.openai.key' => 'test-key']);
    Mail::fake();
    $observedOptions = [];
    Http::fake(function ($request, $options) use (&$observedOptions) {
        $observedOptions = $options;

        return Http::response(['text' => 'operator phrase']);
    });
    $legacy = (new ProcessFile($search, $file))->onQueue('default');
    // Old serialized jobs had no timeout property and do not run the new constructor.
    unset($legacy->timeout);
    Bus::batch([$legacy])->onConnection('database')->onQueue('default')->dispatch();
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--tries' => 3, '--timeout' => 720])->assertSuccessful();
    expect($observedOptions['connect_timeout'])->toEqual(10)
        ->and($observedOptions['timeout'])->toEqual(600);
    expect($file->fresh()->status)->toBe(FileStatus::Transcribed)
        ->and($search->fresh()->query_total)->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'default')->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'lightweight')->count())->toBe(1)
        ->and(DB::table('job_batches')->first()->pending_jobs)->toBe(0);
});

it('supports standalone retries and redacts provider failure content', function () {
    [$search, $file] = historicalSearch(User::factory()->create());
    config(['services.openai.key' => 'test-key']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => 'PRIVATE PROVIDER BODY'], 400)]);
    $job = new ProcessFile($search, $file, true);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Error processing file');
    expect($file->fresh()->status)->toBe(FileStatus::Failed);
});
