<?php

namespace App\Modules\Administrators\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: persist a new administrator account and assign its role.
 *
 * The `name` column (used by the authentication flow) is derived from the
 * first/last name so the rest of the app keeps working unchanged.
 */
final class CreateAdministratorAction extends BaseAction
{
    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, role: string}  $validated
     */
    public function handle(array $validated, User $actor): User
    {
        $administrator = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => trim($validated['first_name'].' '.$validated['last_name']),
            'email' => $validated['email'],
            // The model's "hashed" cast takes care of secure hashing.
            'password' => $validated['password'],
            'status' => 'active',
            'email_verified_at' => now(),
            'created_by' => $actor->id,
        ]);

        $administrator->assignRole($validated['role']);

        return $administrator;
    }
}
