<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;
use Illuminate\Support\Arr;

/**
 * Single unit of work: persist a platform user's profile changes.
 *
 * The `name` column (used by the rest of the app) is kept in sync with the
 * first/last name so the auth flow keeps working unchanged.
 */
final class UpdateUserAction extends BaseAction
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(User $user, array $validated): User
    {
        $first = $validated['first_name'] ?? $user->first_name;
        $last = $validated['last_name'] ?? $user->last_name;

        $user->fill(Arr::only($validated, [
            'first_name',
            'last_name',
            'email',
            'phone',
            'address',
            'birthday',
            'user_type',
        ]));

        $user->name = trim($first.' '.$last);

        if ($user->isDirty()) {
            $user->save();
        }

        return $user;
    }
}
