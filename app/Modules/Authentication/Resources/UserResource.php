<?php

namespace App\Modules\Authentication\Resources;

use App\Modules\ClientAuthentication\Services\ClientProfileService;
use App\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Shapes an administrator into the standard "data" payload.
 *
 * Roles and permissions are included so the frontend can power its route
 * guards (RequireRole / RequirePermission) without extra round-trips.
 */
class UserResource extends BaseResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Shown in the admin web header; null until one is uploaded.
            'profile_photo_url' => ClientProfileService::photoUrl($this->profile_photo_path),
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
