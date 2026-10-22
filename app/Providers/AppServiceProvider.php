<?php

namespace App\Providers;

use App\Listeners\LogQueueTiming;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LogQueueTiming::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([JobProcessing::class, JobProcessed::class, JobExceptionOccurred::class] as $event) {
            Event::listen($event, [LogQueueTiming::class, 'record']);
        }
    }
}
