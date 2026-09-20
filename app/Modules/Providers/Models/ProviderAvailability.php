<?php

namespace App\Modules\Providers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday window in a provider's published schedule.
 *
 * Times are wall clock (`H:i`) in the platform's single timezone — the same
 * basis bookings are recorded in — so no conversion happens anywhere.
 */
class ProviderAvailability extends Model
{
    protected $table = 'provider_availabilities';

    protected $fillable = [
        'provider_profile_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    /** 0 = Sunday … 6 = Saturday, matching Carbon's dayOfWeek. */
    public const DAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    public function dayName(): string
    {
        return self::DAYS[$this->day_of_week] ?? '';
    }

    /** Postgres returns `09:00:00`; the API always speaks `09:00`. */
    public function startsAt(): string
    {
        return self::minutes($this->start_time) !== null
            ? substr((string) $this->start_time, 0, 5)
            : (string) $this->start_time;
    }

    public function endsAt(): string
    {
        return self::minutes($this->end_time) !== null
            ? substr((string) $this->end_time, 0, 5)
            : (string) $this->end_time;
    }

    /**
     * Minutes since midnight for an `H:i` or `H:i:s` value, or null when the
     * value is not a time at all.
     */
    public static function minutes(?string $time): ?int
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }
}
