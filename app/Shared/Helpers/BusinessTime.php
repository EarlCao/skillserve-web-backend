<?php

namespace App\Shared\Helpers;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Times are stored in UTC; people book and work in the platform's business
 * timezone (Asia/Manila by default, BUSINESS_TIMEZONE).
 *
 * - Incoming schedule times are converted to UTC before they are stored. A
 *   value without an offset is read as business-time wall clock, which is what
 *   a person picking "9:00 AM" meant.
 * - Provider hours ("09:00–17:00") and anything shown as text in messages are
 *   business-time wall clock.
 */
final class BusinessTime
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Manila');
    }

    /** Parse a client-supplied date-time and return it in UTC. */
    public static function toUtc(string $value): Carbon
    {
        return Carbon::parse($value, self::timezone())->utc();
    }

    /** The same instant in the business timezone, for wall-clock rules and display. */
    public static function local(CarbonInterface $instant): Carbon
    {
        return Carbon::instance($instant)->setTimezone(self::timezone());
    }

    /** "Oct 5, 2026 9:00 AM" in the business timezone. */
    public static function format(?CarbonInterface $instant): ?string
    {
        return $instant ? self::local($instant)->format('M j, Y g:i A') : null;
    }
}
