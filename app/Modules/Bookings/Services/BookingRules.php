<?php

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Exceptions\ApiException;

/**
 * The booking rules an administrator configures in System Settings
 * (Marketplace → commission, Booking → enabled / cancellation window / fees),
 * read in one place.
 *
 * A cancellation is *late* when a confirmed booking is called off less than
 * `cancellation_window_hours` before it starts. Late cancellations are still
 * allowed; they record a fee (a percentage of the total) owed by whoever
 * cancelled — off-platform, like the payment. Pending requests and
 * administrator cancellations never carry a fee.
 */
class BookingRules
{
    public function __construct(private readonly SettingsService $settings) {}

    public function assertBookingEnabled(): void
    {
        if (! $this->settings->value('booking', 'booking_enabled')) {
            throw new ApiException(
                'New bookings are paused right now. Please try again later.',
                422,
                errors: ['booking' => ['Bookings are currently turned off by the platform.']],
            );
        }
    }

    /** SkillServe's share of a booking, from the configured commission rate. */
    public function platformFee(float $price): float
    {
        $rate = (float) $this->settings->value('marketplace', 'commission_rate');

        return round($price * max(0.0, min(100.0, $rate)) / 100, 2);
    }

    public function windowHours(): int
    {
        return (int) $this->settings->value('booking', 'cancellation_window_hours');
    }

    /** Whether cancelling [$booking] now would be late. */
    public function isLate(Booking $booking): bool
    {
        return $booking->status === 'confirmed'
            && $booking->scheduled_date !== null
            && now()->diffInMinutes($booking->scheduled_date, false) < $this->windowHours() * 60;
    }

    /** The fee [$side] ('client' or 'provider') would owe for cancelling now. */
    public function cancellationFee(Booking $booking, string $side): float
    {
        if (! $this->isLate($booking)) {
            return 0.0;
        }

        $percent = (float) $this->settings->value('booking', "{$side}_cancellation_fee_percent");

        return round((float) $booking->total_price * max(0.0, min(100.0, $percent)) / 100, 2);
    }

    /**
     * What the app shows before a cancellation: the rule and what it would
     * cost right now.
     *
     * @return array{window_hours: int, fee_percent: float, is_late: bool, fee_if_cancelled_now: string}
     */
    public function policyFor(Booking $booking, string $side): array
    {
        return [
            'window_hours' => $this->windowHours(),
            'fee_percent' => (float) $this->settings->value('booking', "{$side}_cancellation_fee_percent"),
            'is_late' => $this->isLate($booking),
            'fee_if_cancelled_now' => number_format($this->cancellationFee($booking, $side), 2, '.', ''),
        ];
    }
}
