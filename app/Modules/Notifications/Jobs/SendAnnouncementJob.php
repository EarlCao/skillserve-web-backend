<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Services\AnnouncementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendAnnouncementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Announcement $announcement) {}

    public function handle(): void
    {
        $this->announcement->refresh();

        if ($this->announcement->status === 'sent') {
            return;
        }

        app(AnnouncementService::class)
            ->deliver($this->announcement);
    }

    public function failed(Throwable $exception): void
    {
        Announcement::query()
            ->whereKey($this->announcement->id)
            ->where('status', '!=', 'sent')
            ->update(['status' => 'failed']);
    }
}
