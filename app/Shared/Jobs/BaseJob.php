<?php

namespace App\Shared\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for queued jobs (emails, notifications, exports, ...).
 *
 * Future jobs should extend this class instead of implementing ShouldQueue
 * directly, so retry/backoff/timeout defaults stay consistent.
 */
abstract class BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Number of times the job may be attempted. */
    public int $tries = 3;

    /** Seconds to wait between retries. */
    public array $backoff = [5, 15, 60];

    /** Maximum seconds the job may run. */
    public int $timeout = 120;

    /**
     * Execute the job.
     */
    abstract public function handle(): void;
}
