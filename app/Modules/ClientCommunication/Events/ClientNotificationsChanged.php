<?php

namespace App\Modules\ClientCommunication\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The user archived, restored or deleted notifications: their other open
 * sessions reload the feed and the unread count. Carries no notification
 * data — only that something changed.
 */
class ClientNotificationsChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'client.notifications.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
