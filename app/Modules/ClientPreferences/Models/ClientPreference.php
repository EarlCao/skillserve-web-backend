<?php

namespace App\Modules\ClientPreferences\Models;

use App\Models\User;
use App\Shared\Notifications\BaseNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mobile account's settings. One row per user, created on first read.
 *
 * Read server-side as well as by the app: the notification flags gate
 * delivery in {@see BaseNotification}, and
 * `private_profile` removes a provider from public discovery.
 */
class ClientPreference extends Model
{
    /** Notification categories, in the order the app presents them. */
    public const NOTIFICATION_CATEGORIES = [
        'booking' => 'booking_notifications',
        'service' => 'service_notifications',
        'message' => 'message_notifications',
        'announcement' => 'announcement_notifications',
    ];

    public const THEMES = ['light', 'dark', 'system'];

    protected $fillable = [
        'user_id',
        'booking_notifications',
        'service_notifications',
        'message_notifications',
        'announcement_notifications',
        'private_profile',
        'activity_personalization',
        'reduce_motion',
        'theme',
    ];

    protected function casts(): array
    {
        return [
            'booking_notifications' => 'boolean',
            'service_notifications' => 'boolean',
            'message_notifications' => 'boolean',
            'announcement_notifications' => 'boolean',
            'private_profile' => 'boolean',
            'activity_personalization' => 'boolean',
            'reduce_motion' => 'boolean',
        ];
    }

    /**
     * The values a brand-new account starts with. Kept here so the API, the
     * database defaults and the app all agree on one set.
     *
     * @return array<string, bool|string>
     */
    public static function defaults(): array
    {
        return [
            'booking_notifications' => true,
            'service_notifications' => true,
            'message_notifications' => true,
            'announcement_notifications' => true,
            'private_profile' => false,
            'activity_personalization' => true,
            'reduce_motion' => false,
            'theme' => 'system',
        ];
    }

    /** Whether this account still wants the given notification category. */
    public function allowsNotification(string $category): bool
    {
        $column = self::NOTIFICATION_CATEGORIES[$category] ?? null;

        // An unknown category is never silently muted: account security and
        // anything else uncategorised must always reach the user.
        return $column === null || (bool) $this->{$column};
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
