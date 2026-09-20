<?php

namespace App\Modules\ClientAuthentication\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The two outcomes of POST /auth/google, told apart by
 * `registration_required`: an issued session, or a draft to prefill the
 * sign-up form with because no account exists for this Google identity yet.
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

        return array_merge(
            ['registration_required' => false],
            (new ClientAuthResource($this->resource))->toArray($request),
        );
    }
}
