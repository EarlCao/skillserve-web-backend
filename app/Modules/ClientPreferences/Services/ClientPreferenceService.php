<?php

namespace App\Modules\ClientPreferences\Services;

use App\Models\User;
use App\Modules\ClientPreferences\Models\ClientPreference;

/**
 * Reads and writes a mobile account's settings.
 *
 * Every caller goes through {@see forUser()}, which materialises the row on
 * first use, so the rest of the application can treat preferences as always
 * present instead of null-checking everywhere.
 */
class ClientPreferenceService
{
    /** The account's preferences, created with defaults if absent. */
    public function forUser(User $user): ClientPreference
    {
        return ClientPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            ClientPreference::defaults(),
        );
    }

    /**
     * Apply a partial update: only the keys present are written, so the app
     * can send a single toggle without restating the rest.
     *
     * @param  array<string, bool|string>  $validated
     */
    public function update(User $user, array $validated): ClientPreference
    {
        $preferences = $this->forUser($user);

        $attributes = array_intersect_key($validated, ClientPreference::defaults());

        if ($attributes !== []) {
            $preferences->fill($attributes)->save();
        }

        return $preferences->fresh();
    }

    /**
     * Whether a notification category may be delivered to this notifiable.
     *
     * Anything that is not a mobile account (administrators, and the
     * pending-registration notifiable used during sign-up) is never gated,
     * and an account with no row yet falls back to the defaults.
     */
    public function allowsNotification(object $notifiable, ?string $category): bool
    {
        if ($category === null || ! $notifiable instanceof User || ! $notifiable->isMobileAccount()) {
            return true;
        }

        $preferences = ClientPreference::query()
            ->where('user_id', $notifiable->id)
            ->first();

        if (! $preferences) {
            return (bool) (ClientPreference::defaults()[
                ClientPreference::NOTIFICATION_CATEGORIES[$category] ?? ''
            ] ?? true);
        }

        return $preferences->allowsNotification($category);
    }
}
