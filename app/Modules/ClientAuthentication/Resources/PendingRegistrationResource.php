<?php

namespace App\Modules\ClientAuthentication\Resources;

use App\Shared\Enums\AccountRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sign-up that is not an account yet. Deliberately carries no session and
 * no account id: neither exists until the code is confirmed and (for
 * sign-ups that started without one) the password chosen.
 *
 * `registration_token` appears only in the response that started the
 * sign-up; the app keeps it for POST /auth/complete-registration.
 */
class PendingRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'verification_required' => ! $this->hasVerifiedEmail(),
            'password_required' => $this->needsPassword(),
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'user_type' => AccountRole::userTypeFor((int) $this->role_id),
            'code_expires_at' => $this->email_otp_expires_at?->toIso8601String(),
            'registration_expires_at' => $this->expires_at?->toIso8601String(),
            'registration_token' => $this->when($this->plainRegistrationToken !== null, fn () => $this->plainRegistrationToken),
        ];
    }
}
