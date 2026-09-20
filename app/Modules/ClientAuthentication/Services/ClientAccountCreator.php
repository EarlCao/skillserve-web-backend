<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Enums\AccountRole;
use Illuminate\Support\Facades\DB;

/**
 * Creates the `users` row (and, for providers, the pending
 * `provider_profiles` row) for a mobile sign-up that has completed its
 * requirements — a confirmed email OTP, or a verified Google identity.
 *
 * This is the only place a mobile account is written, so customer and
 * provider sign-ups, whichever screen they came from, produce the same
 * shape of account.
 */
class ClientAccountCreator
{
    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, role_id: int, business_name?: string|null, specialization?: string|null, experience_years?: int|null, bio?: string|null}  $attributes
     */
    public function create(array $attributes, ?string $googleSub = null): User
    {
        return DB::transaction(function () use ($attributes, $googleSub): User {
            $firstName = (string) $attributes['first_name'];
            $lastName = (string) $attributes['last_name'];
            $roleId = (int) $attributes['role_id'];

            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => trim($firstName.' '.$lastName),
                'email' => $attributes['email'],
                // Already-hashed values pass through the `hashed` cast.
                'password' => $attributes['password'],
                'role_id' => $roleId,
                'status' => 'active',
            ]);

            // Not mass-assignable: the account only reaches this point once
            // the address has been proven (OTP or Google), so it is marked
            // verified here rather than left for a second round-trip.
            $user->forceFill([
                'email_verified_at' => now(),
                'google_sub' => $googleSub,
            ])->save();

            if ($roleId === AccountRole::Provider->value) {
                ProviderProfile::create([
                    'user_id' => $user->id,
                    'business_name' => $attributes['business_name'] ?? null,
                    'bio' => $attributes['bio'] ?? null,
                    'specialization' => $attributes['specialization'] ?? null,
                    'experience_years' => $attributes['experience_years'] ?? 0,
                    'verification_status' => 'pending',
                ]);
            }

            return $user->fresh();
        });
    }
}
