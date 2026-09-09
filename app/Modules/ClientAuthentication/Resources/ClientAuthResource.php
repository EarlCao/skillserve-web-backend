<?php

namespace App\Modules\ClientAuthentication\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientAuthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource['token'],
            'token_type' => 'Bearer',
            'expires_at' => $this->resource['expires_at'],
            'refresh_token' => $this->resource['refresh_token'],
            'refresh_expires_at' => $this->resource['refresh_expires_at'],
            'user' => new ClientUserResource($this->resource['user']),
        ];
    }
}
