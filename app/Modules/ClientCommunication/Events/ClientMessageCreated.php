<?php

namespace App\Modules\ClientCommunication\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new booking message, pushed to the receiver so an open chat updates without
 * waiting for a poll.
 *
 * This is chat content, not an alert, so it is deliberately *not* gated by the
 * "Message notifications" setting: muting the banner must not stop a
 * conversation the user is looking at from updating. The companion
 * BookingMessageNotification carries the muteable alert.
 */
class ClientMessageCreated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /**
     * Realtime delivery is best-effort (the message is already stored and the
     * app refetches the thread), so an unreachable WebSocket is not retried.
     */
    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $message
     */
    public function __construct(
        public readonly int $receiverId,
        public readonly int $bookingId,
        public readonly array $message,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->receiverId}")];
    }

    public function broadcastAs(): string
    {
        return 'client.message.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'message' => $this->message,
        ];
    }
}
