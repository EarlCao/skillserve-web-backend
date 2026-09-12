<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Support\Facades\DB;

/**
 * Self-service provider registration for the mobile app.
 *
 * Creates a roleless `provider` user (no admin surface role), an associated
 * `provider_profiles` row pending verification, and issues the same mobile
 * session shape as customer registration. Email verification is not required
 * for providers; administrator verification gates marketplace actions instead.
 */
class ClientProviderRegistrationService
{
    public function __construct(
        private readonly ClientSessionService $sessionService,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, business_name?: string|null, specialization: string, experience_years?: int, bio?: string|null}  $validated
     * @return array<string, mixed>
     */
    public function register(array $validated): array
    {
        $session = DB::transaction(function () use ($validated): array {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'email' => $validated['email'],
                'password' => $validated['password'],
                'user_type' => 'provider',
                'status' => 'active',
            ]);

            // `email_verified_at` is not mass-assignable; mark providers as
            // verified immediately since administrator verification (not
            // email confirmation) gates their marketplace access.
            $user->forceFill(['email_verified_at' => now()])->save();

            ProviderProfile::create([
                'user_id' => $user->id,
                'business_name' => $validated['business_name'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'specialization' => $validated['specialization'],
                'experience_years' => $validated['experience_years'] ?? 0,
                'verification_status' => 'pending',
            ]);

            return $this->sessionService->issue($user);
        });

        return $session;
    }
}
