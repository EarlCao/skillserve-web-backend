<?php

namespace App\Modules\Administrators\Resources;

use App\Modules\Authentication\Resources\UserResource;
use Illuminate\Http\Request;

/**
 * Shapes an administrator for the management screens: everything from
 * UserResource plus activation status, last login and creator.
 *
 * Requires the roles (with permissions) and createdBy relations to be loaded
 * to avoid N+1 queries — the service eager-loads them.
 */
class AdministratorResource extends UserResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ]);
    }
}
