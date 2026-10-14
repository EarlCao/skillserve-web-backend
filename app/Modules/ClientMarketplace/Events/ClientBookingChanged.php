<?php

namespace App\Modules\ClientMarketplace\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something on a booking changed — its status, a payment, a payment proof —
 * pushed to one party's open app so the booking screens reload at once.
 *
 * Unlike a notification it carries no message and is never muted: turning
 * off "Booking updates" silences alerts, not the screen the user is looking
 * at. Best effort; the app also reloads when it is opened.
 */
class ClientBookingChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $bookingId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'client.booking.changed';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['booking_id' => $this->bookingId];
    }
}
