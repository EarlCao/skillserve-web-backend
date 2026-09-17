<?php

namespace App\Shared\Listeners;

use App\Models\User;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/**
 * Keeps users.role_id in step when staff roles are assigned or removed
 * through Spatie (assignRole, syncRoles, removeRole).
 */
class SyncUserRoleId
{
    public function handle(RoleAttachedEvent|RoleDetachedEvent $event): void
    {
        if ($event->model instanceof User) {
            $event->model->syncRoleIdFromStaffRoles();
        }
    }
}
