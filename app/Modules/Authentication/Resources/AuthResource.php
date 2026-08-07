<?php

namespace App\Modules\Authentication\Resources;

use App\Shared\Resources\BaseResource;

/**
 * Shapes the login response: access token + the authenticated user.
 *
 * This resource wraps a plain array (not a model), so keys are read from
 * $this->resource directly — JsonResource's magic __get only proxies object
 * properties.
 */
class AuthResource extends BaseResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'token' => $this->resource['token'],
            'token_type' => $this->resource['token_type'],
            'expires_at' => $this->resource['expires_at'],
            'user' => new UserResource($this->resource['user']),
        ];
    }
}
