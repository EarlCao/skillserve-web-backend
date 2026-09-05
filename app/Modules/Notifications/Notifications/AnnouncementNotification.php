<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Notifications\Models\Announcement;
use App\Shared\Notifications\BaseNotification;
use Illuminate\Bus\Queueable;

class AnnouncementNotification extends BaseNotification
{
    use Queueable;

    public function __construct(private readonly Announcement $announcement) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'message' => $this->announcement->message,
            'type' => 'announcement',
        ];
    }
}
