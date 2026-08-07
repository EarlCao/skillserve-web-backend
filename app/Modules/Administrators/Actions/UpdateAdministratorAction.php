<?php

namespace App\Modules\Administrators\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: update an administrator's profile (name, email, role).
 *
 * Status changes delegate to SetAdministratorStatusAction so the
 * activate/deactivate business rules always apply, regardless of whether the
 * status was changed here or via the dedicated status endpoint.
 */
final class UpdateAdministratorAction extends BaseAction
{
    public function __construct(
        private readonly SetAdministratorStatusAction $setAdministratorStatusAction,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(User $administrator, array $validated, User $actor): User
    {
        if (array_key_exists('status', $validated)) {
            $this->setAdministratorStatusAction->handle($administrator, $actor, $validated['status']);
        }

        $firstName = $validated['first_name'] ?? $administrator->first_name;
        $lastName = $validated['last_name'] ?? $administrator->last_name;

        $administrator->fill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim($firstName.' '.$lastName),
            'email' => $validated['email'] ?? $administrator->email,
        ]);
        $administrator->save();

        if (array_key_exists('role', $validated)) {
            $administrator->syncRoles($validated['role']);
        }

        return $administrator;
    }
}
