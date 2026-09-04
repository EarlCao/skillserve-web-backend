<?php

namespace App\Modules\Administrators\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Shapes a permission for the permission matrix. The `module` field groups
 * permissions under a feature area (e.g. "manage administrators" →
 * "Administrators") so the frontend can render the matrix per module.
 */
class PermissionResource extends BaseResource
{
    /**
     * Prefixes that introduce the module part of a permission name.
     */
    private const VERB_PREFIXES = [
        'manage ', 'view ', 'create ', 'edit ', 'update ', 'delete ', 'export ',
        'approve ', 'reject ', 'suspend ', 'activate ', 'investigate ', 'resolve ',
    ];

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'module' => $this->module(),
            'guard_name' => $this->guard_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Derive the feature module from the permission name.
     */
    public function module(): string
    {
        $name = (string) $this->name;

        foreach (self::VERB_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return ucfirst(trim(substr($name, strlen($prefix))));
            }
        }

        return 'Other';
    }
}
