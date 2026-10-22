<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

class LogQueueTiming
{
    private array $started = [];

    public function record(JobProcessing|JobProcessed|JobExceptionOccurred $event): void
    {
        $key = $event->connectionName.':'.$event->job->getJobId();
        $starting = $event instanceof JobProcessing;
        $now = hrtime(true);
        $context = [
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'job_id' => $event->job->getJobId(),
            'job_class' => $event->job->resolveName(),
            'attempts' => $event->job->attempts(),
            'phase' => $starting ? 'started' : ($event instanceof JobProcessed ? 'processed' : 'exception'),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
        if ($starting) {
            $this->started[$key] = $now;
        } else {
            $context['duration_ms'] = isset($this->started[$key]) ? round(($now - $this->started[$key]) / 1e6) : null;
            unset($this->started[$key]);
        }
        // Never include serialized payloads, exception text, bindings or content.
        Log::info('pipeline_job', $context);
    }
}
