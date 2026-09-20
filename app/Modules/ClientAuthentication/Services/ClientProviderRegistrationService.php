<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Shared\Enums\AccountRole;

/**
 * Self-service provider registration for the mobile app.
 *
 * Like customer sign-up, the account is deferred: the professional details
 * are parked with the registration and the `users` + `provider_profiles`
 * rows are written only once the emailed OTP is confirmed. Administrator
 * verification then gates marketplace actions.
 */
class ClientProviderRegistrationService
{
    public function __construct(
        private readonly PendingRegistrationService $pendingRegistrations,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, business_name?: string|null, specialization: string, experience_years?: int, bio?: string|null}  $validated
     */
    public function register(array $validated): PendingRegistration
    {
        return $this->pendingRegistrations->start($validated, AccountRole::Provider->value);
    }
}
