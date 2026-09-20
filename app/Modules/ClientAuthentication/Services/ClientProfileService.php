<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The account owner's own profile: the details the mobile "Edit Profile"
 * screen maintains.
 *
 * Only the signed-in user's row is ever touched — the caller passes the
 * authenticated user, and no identifier is accepted from the request, so
 * one account can never edit another's profile.
 */
class ClientProfileService
{
    /** Photos are addressed by path; the URL is derived from this disk. */
    public static function disk(): string
    {
        return (string) config('client-auth.profile_photo_disk', 'public');
    }

    /** Public URL for a stored photo path, or null when there is none. */
    public static function photoUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk(self::disk())->url($path);
    }

    /**
     * Update the editable profile fields. Only keys actually present in the
     * request are written, so a partial update cannot blank a field the
     * screen did not send.
     *
     * @param  array{first_name?: string, last_name?: string, phone?: string|null, address?: string|null}  $validated
     */
    public function update(User $user, array $validated): User
    {
        $attributes = array_intersect_key($validated, array_flip([
            'first_name',
            'last_name',
            'phone',
            'address',
        ]));

        if ($attributes === []) {
            return $user;
        }

        // `name` is the denormalised display name used across the admin
        // surface; keep it in step with the parts the user just edited.
        if (array_key_exists('first_name', $attributes) || array_key_exists('last_name', $attributes)) {
            $first = $attributes['first_name'] ?? $user->first_name;
            $last = $attributes['last_name'] ?? $user->last_name;
            $attributes['name'] = trim($first.' '.$last);
        }

        $user->fill($attributes)->save();

        return $user->fresh();
    }

    /**
     * Replace the profile photo, deleting whatever it replaces so abandoned
     * files do not accumulate.
     */
    public function updatePhoto(User $user, UploadedFile $photo): User
    {
        $disk = Storage::disk(self::disk());
        $previous = $user->profile_photo_path;

        $path = $photo->store('profile-photos', self::disk());

        // Not mass-assignable: the path is derived from the stored file, so
        // a client can never point the column at an arbitrary location.
        DB::transaction(function () use ($user, $path): void {
            $user->forceFill(['profile_photo_path' => $path])->save();
        });

        if ($previous && $previous !== $path) {
            $disk->delete($previous);
        }

        return $user->fresh();
    }

    /** Remove the profile photo and its file. */
    public function removePhoto(User $user): User
    {
        $previous = $user->profile_photo_path;

        if ($previous === null) {
            return $user;
        }

        $user->forceFill(['profile_photo_path' => null])->save();
        Storage::disk(self::disk())->delete($previous);

        return $user->fresh();
    }
}
