<?php

namespace App\Jobs;

use App\Enums\SearchStatus;
use App\Models\Search;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class BatchUpload implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Search $search,
        protected array $fileArray,
        protected string $timezone,
    ) {
        $this->onQueue('ingest');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $fileCount = count($this->fileArray);

        $firstJob = new UploadFile($this->search, $this->fileArray[0], $this->timezone, $fileCount);

        $batch = Bus::batch([$firstJob])->onQueue('ingest')->allowFailures();

        foreach (array_slice($this->fileArray, 1) as $file) {
            $batch->add(new UploadFile($this->search, $file, $this->timezone, $fileCount));
        }

        $batch->dispatch();
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Batch failed', ['search_id' => $this->search->id, 'exception_class' => $exception::class]);

        $this->search->status = SearchStatus::Failed;
        $this->search->save();
    }
}
