<?php

namespace App\Modules\ClientAuthentication\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The three outcomes of POST /auth/google:
 *  - `password_required`: the account exists; ask for its password and call again;
 *  - `registration_required`: no account yet; a draft to prefill the sign-up form;
 *  - otherwise an issued session.
 */
class ClientGoogleAuthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource['registration_required'] ?? false) {
            return [
                'registration_required' => true,
                'google' => $this->resource['google'],
            ];
        }

        if ($this->resource['password_required'] ?? false) {
            return [
                'registration_required' => false,
                'password_required' => true,
                'email' => $this->resource['email'],
            ];
        }

        return array_merge(
            ['registration_required' => false, 'password_required' => false],
            (new ClientAuthResource($this->resource))->toArray($request),
        );
    }
}
