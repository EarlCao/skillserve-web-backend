<?php

namespace App\Modules\Authentication\Resources;

use App\Shared\Resources\BaseResource;

/**
 * Shapes an administrator into the standard "data" payload.
 *
 * Roles and permissions are included so the frontend can power its route
 * guards (RequireRole / RequirePermission) without extra round-trips.
 */
class UserResource extends BaseResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
