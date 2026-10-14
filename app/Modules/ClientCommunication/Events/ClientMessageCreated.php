<?php

namespace App\Modules\ClientCommunication\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
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
 *
 * Broadcast immediately rather than through the queue, so an open chat moves
 * as soon as the message is stored. Delivery is best-effort (the app also
 * refetches the thread), so BookingMessageService logs a failed push instead
 * of failing the send.
 */
class ClientMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $message
     */
    public function __construct(
        public readonly int $receiverId,
        public readonly ?int $bookingId,
        public readonly array $message,
        // Set instead of bookingId for a conversation before a booking.
        public readonly ?int $inquiryId = null,
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
            'inquiry_id' => $this->inquiryId,
            'message' => $this->message,
        ];
    }
}
