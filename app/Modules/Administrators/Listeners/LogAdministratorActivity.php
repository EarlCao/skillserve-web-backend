<?php

namespace App\Modules\Administrators\Listeners;

use App\Models\User;
use App\Modules\Administrators\Events\AdministratorCreated;
use App\Modules\Administrators\Events\AdministratorStatusChanged;
use App\Modules\Administrators\Events\AdministratorUpdated;
use App\Modules\Administrators\Events\RoleCreated;
use App\Modules\Administrators\Events\RoleDeleted;
use App\Modules\Administrators\Events\RolePermissionsSynced;
use App\Modules\Administrators\Events\RoleUpdated;
use Spatie\Permission\Models\Role;

/**
 * Persists Administrator Management events into the Spatie activity log.
 *
 * Registered explicitly in AppServiceProvider (module listeners live outside
 * app/Listeners, so auto-discovery does not apply).
 */
class LogAdministratorActivity
{
    /**
     * Handle the administrator-module events.
     */
    public function handle(
        AdministratorCreated|AdministratorUpdated|AdministratorStatusChanged|
        RoleCreated|RoleUpdated|RoleDeleted|RolePermissionsSynced $event,
    ): void {
        match (true) {
            $event instanceof AdministratorCreated => $this->log(
                'administrators', $event->actor, $event->administrator,
                ['created' => $event->data], 'administrator_created',
            ),
            $event instanceof AdministratorUpdated => $this->log(
                'administrators', $event->actor, $event->administrator,
                ['before' => $event->before, 'after' => $event->after], 'administrator_updated',
            ),
            $event instanceof AdministratorStatusChanged => $this->log(
                'administrators', $event->actor, $event->administrator,
                ['from' => $event->from, 'to' => $event->to], 'administrator_status_changed',
            ),
            $event instanceof RoleCreated => $this->log(
                'roles', $event->actor, $event->role,
                ['created' => $event->data], 'role_created',
            ),
            $event instanceof RoleUpdated => $this->log(
                'roles', $event->actor, $event->role,
                ['before' => $event->before, 'after' => $event->after], 'role_updated',
            ),
            $event instanceof RoleDeleted => $this->log(
                'roles', $event->actor, $event->role,
                ['name' => $event->name], 'role_deleted',
            ),
            default => $this->log(
                'roles', $event->actor, $event->role,
                ['permissions' => $event->permissions], 'role_permissions_synced',
            ),
        };
    }

    /**
     * Write a single activity-log entry.
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(string $logName, User $actor, User|Role $subject, array $properties, string $description): void
    {
        activity($logName)
            ->causedBy($actor)
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }
}
