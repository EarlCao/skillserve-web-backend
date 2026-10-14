<?php

namespace App\Modules\Notifications\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new admin Inbox alert, pushed to that administrator's open admin pages
 * at once so the bell updates without a reload. Best effort: the alert is
 * already stored, and the admin web refetches the Inbox on its own too.
 */
class AdminNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $data,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'admin.notification.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['notification' => $this->data];
    }
}
