<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;

/**
 * What a customer is told about a provider they booked or messaged once an
 * administrator suspended, banned or deleted that provider. One place for the
 * wording, shared by the booking and chat payloads and the notification.
 */
final class ProviderStandingPresenter
{
    /**
     * @return array{account_status: string, account_notice: ?string}
     */
    public static function present(ProviderProfile $provider, ?Booking $booking = null): array
    {
        $reason = $provider->unavailability();

        return [
            'account_status' => $reason ?? 'active',
            'account_notice' => $reason === null ? null : self::notice($reason, $booking),
        ];
    }

    /**
     * @param  string  $reason  'suspended', 'banned' or 'deleted'
     */
    public static function notice(string $reason, ?Booking $booking = null): string
    {
        $what = match ($reason) {
            'deleted' => "This provider's account was deleted",
            'banned' => 'This provider has been banned from SkillServe',
            default => 'This provider has been suspended by SkillServe',
        };

        if ($booking === null || ! in_array($booking->status, Booking::OPEN_STATUSES, true)) {
            return "{$what}.";
        }

        $next = in_array($booking->status, ['pending', 'confirmed'], true)
            ? 'You can cancel it with no cancellation fee. If you already paid them, contact support and we will help you get it back.'
            : 'Contact support and we will help you with it.';

        return "{$what}, so they cannot work on this booking. {$next}";
    }
}
