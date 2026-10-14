<?php

namespace App\Modules\ClientCommunication\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A stored notification, pushed to the account's phone at once.
 *
 * Broadcast immediately rather than through the queue, so it does not wait
 * for the worker's next poll. Delivery is best-effort: the notification is
 * already stored and the app also polls, so the dispatcher logs a failed push
 * instead of failing (BroadcastClientNotification).
 */
class ClientNotificationCreated implements ShouldBroadcastNow
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
        return 'client.notification.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['notification' => $this->data];
    }
}
