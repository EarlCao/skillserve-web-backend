<?php

namespace App\Modules\Bookings\Notifications;

use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientMarketplace\Resources\ProviderStandingPresenter;
use App\Shared\Notifications\BaseNotification;

/**
 * Tells a customer that the provider of one of their open bookings was
 * suspended, banned or deleted by an administrator — or is back after a
 * suspension. Not mutable: the customer may need to cancel or get money back.
 */
class ProviderStandingNotification extends BaseNotification
{
    /**
     * @param  string  $standing  'suspended', 'banned', 'deleted' or 'active'
     */
    public function __construct(
        private readonly Booking $booking,
        private readonly string $providerName,
        private readonly string $standing,
    ) {}

    public function toArray(object $notifiable): array
    {
        [$title, $message] = match ($this->standing) {
            'active' => [
                "{$this->providerName} is available again",
                "{$this->providerName} can work on your booking {$this->booking->booking_number} again.",
            ],
            'deleted' => [
                "{$this->providerName}'s account was deleted",
                ProviderStandingPresenter::notice('deleted', $this->booking),
            ],
            'banned' => [
                "{$this->providerName} was banned",
                ProviderStandingPresenter::notice('banned', $this->booking),
            ],
            default => [
                "{$this->providerName} was suspended",
                ProviderStandingPresenter::notice('suspended', $this->booking),
            ],
        };

        return [
            'type' => 'booking_provider_status',
            'action' => $this->standing,
            'title' => $title,
            'message' => $message,
            'body' => $message,
            'booking_id' => $this->booking->id,
            'booking_number' => $this->booking->booking_number,
            'service_title' => $this->booking->service?->title,
            'status' => $this->booking->status,
        ];
    }
}
