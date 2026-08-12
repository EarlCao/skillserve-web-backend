<?php

namespace App\Modules\ServiceCategories\Policies;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for service-category-management actions.
 *
 * Super administrators bypass every check via Gate::before in
 * AppServiceProvider; every other role needs the "manage service categories"
 * permission.
 */
class ServiceCategoryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage service categories');
    }

    public function view(User $user, ServiceCategory $category): bool
    {
        return $user->hasPermissionTo('manage service categories');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage service categories');
    }

    public function update(User $user, ServiceCategory $category): bool
    {
        return $user->hasPermissionTo('manage service categories');
    }

    public function updateStatus(User $user, ServiceCategory $category): bool
    {
        return $user->hasPermissionTo('manage service categories');
    }

    public function delete(User $user, ServiceCategory $category): bool
    {
        return $user->hasPermissionTo('manage service categories');
    }
}
