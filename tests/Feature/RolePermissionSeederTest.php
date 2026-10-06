<?php

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Enums\AccountRole;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('SEED_MODE');
        unset($_ENV['SEED_MODE'], $_SERVER['SEED_MODE']);

        parent::tearDown();
    }

    public function test_admin_only_mode_seeds_the_super_admin_and_nothing_else(): void
    {
        putenv('SEED_MODE=admin-only');
        $_ENV['SEED_MODE'] = $_SERVER['SEED_MODE'] = 'admin-only';

        $this->seed(DatabaseSeeder::class);

        $superAdmin = User::query()->where('email', 'admin@skillserve.test')->firstOrFail();

        $this->assertSame(AccountRole::SuperAdmin->value, (int) $superAdmin->role_id);
        $this->assertTrue($superAdmin->hasRole('super-admin'));

        // Exactly one way in: no second administrator is seeded any more.
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, User::query()->where('email', 'system@skillserve.test')->count());

        $this->postJson('/api/auth/login', [
            'email' => $superAdmin->email,
            'password' => 'SkillServe#2026',
        ])->assertOk();
    }

    public function test_reseeding_does_not_duplicate_the_super_admin(): void
    {
        $this->seed([RolePermissionSeeder::class, RolePermissionSeeder::class]);

        $this->assertSame(1, User::query()->where('email', 'admin@skillserve.test')->count());
    }

    public function test_the_super_admin_holds_every_permission_the_migrations_create(): void
    {
        // Migrations add permissions as modules arrive; the seeder must list
        // them all, or a freshly seeded super-admin silently lacks some.
        $this->seed(RolePermissionSeeder::class);

        $missing = Permission::query()->pluck('name')
            ->diff(Role::findByName('super-admin')->permissions->pluck('name'))
            ->values()
            ->all();

        $this->assertSame([], $missing);
    }
}
