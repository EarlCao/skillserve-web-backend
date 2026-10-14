<?php

namespace App\Modules\Administrators\Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Create an actor with the "manage administrators" permission.
     */
    private function actingAdministrator(): array
    {
        Permission::findOrCreate('manage administrators');
        $role = Role::findOrCreate('admin');
        $role->givePermissionTo('manage administrators');

        $user = User::factory()->create([
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $user->assignRole('admin');

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson('/api/roles')->assertStatus(401);
        $this->getJson('/api/permissions')->assertStatus(401);
    }

    public function test_user_without_permission_gets_403_on_roles_and_permissions(): void
    {
        Permission::findOrCreate('manage administrators');
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/roles')->assertStatus(403);
        $this->withToken($token)->getJson('/api/permissions')->assertStatus(403);
    }

    public function test_index_lists_roles_with_their_permissions(): void
    {
        [, $token] = $this->actingAdministrator();

        Permission::findOrCreate('view reports');
        $role = Role::findOrCreate('reporter');
        $role->givePermissionTo('view reports');

        $this->withToken($token)
            ->getJson('/api/roles?search=reporter')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'reporter')
            ->assertJsonPath('data.0.permissions', ['view reports'])
            ->assertJsonStructure(['meta' => ['pagination']]);
    }

    public function test_store_creates_a_role(): void
    {
        [, $token] = $this->actingAdministrator();

        $this->withToken($token)
            ->postJson('/api/roles', [
                'name' => 'reports-manager',
                'description' => 'Manages operational reports.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'reports-manager')
            ->assertJsonPath('data.description', 'Manages operational reports.');
    }

    public function test_non_super_administrator_cannot_create_a_role_with_protected_permissions(): void
    {
        [, $token] = $this->actingAdministrator();
        Permission::findOrCreate('manage administrators');

        $this->withToken($token)
            ->postJson('/api/roles', [
                'name' => 'privilege-escalator',
                'permissions' => ['manage administrators'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'privilege-escalator']);
    }

    public function test_store_rejects_duplicate_role_names(): void
    {
        [, $token] = $this->actingAdministrator();
        Role::create(['name' => 'reports-manager']);

        $this->withToken($token)
            ->postJson('/api/roles', ['name' => 'reports-manager'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_update_changes_role_name_and_description(): void
    {
        [, $token] = $this->actingAdministrator();
        $role = Role::create(['name' => 'old-name']);

        $this->withToken($token)
            ->putJson("/api/roles/{$role->id}", [
                'name' => 'new-name',
                'description' => 'Renamed role.',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'new-name')
            ->assertJsonPath('data.description', 'Renamed role.');
    }

    public function test_system_default_role_cannot_be_deleted(): void
    {
        [, $token] = $this->actingAdministrator();
        $superAdmin = Role::findOrCreate('super-admin');

        $this->withToken($token)
            ->deleteJson("/api/roles/{$superAdmin->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('roles', ['name' => 'super-admin']);
    }

    public function test_system_default_role_cannot_be_renamed(): void
    {
        [, $token] = $this->actingAdministrator();
        $superAdmin = Role::findOrCreate('super-admin');

        $this->withToken($token)
            ->putJson("/api/roles/{$superAdmin->id}", ['name' => 'root'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_delete_removes_a_regular_role(): void
    {
        [, $token] = $this->actingAdministrator();
        $role = Role::create(['name' => 'disposable']);

        $this->withToken($token)
            ->deleteJson("/api/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('roles', ['name' => 'disposable']);
    }

    public function test_sync_permissions_updates_a_role(): void
    {
        [, $token] = $this->actingAdministrator();

        Permission::findOrCreate('view reports');
        Permission::findOrCreate('manage bookings');
        $role = Role::findOrCreate('reporter');

        $response = $this->withToken($token)
            ->putJson("/api/roles/{$role->id}/permissions", [
                'permissions' => ['view reports', 'manage bookings'],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.permissions');

        // Order is not significant.
        $permissions = $response->json('data.permissions');
        sort($permissions);
        $this->assertSame(['manage bookings', 'view reports'], $permissions);

        // Assigning a user this role now grants the permissions.
        $user = User::factory()->create();
        $user->assignRole('reporter');
        $this->assertTrue($user->hasPermissionTo('view reports'));
    }

    public function test_non_super_administrator_cannot_sync_protected_permissions(): void
    {
        [, $token] = $this->actingAdministrator();
        Permission::findOrCreate('manage administrators');
        $role = Role::findOrCreate('protected-permission-target');

        $this->withToken($token)
            ->putJson("/api/roles/{$role->id}/permissions", [
                'permissions' => ['manage administrators'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => Permission::where('name', 'manage administrators')->value('id'),
        ]);
    }

    public function test_super_administrator_permissions_cannot_be_modified(): void
    {
        [, $token] = $this->actingAdministrator();
        $superAdmin = Role::findOrCreate('super-admin');

        $this->withToken($token)
            ->putJson("/api/roles/{$superAdmin->id}/permissions", [
                'permissions' => ['view reports'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_permission_matrix_has_one_area_per_admin_section_with_view_and_full_control(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        [, $token] = $this->actingAdministrator();

        $response = $this->withToken($token)->getJson('/api/permissions')->assertOk()
            ->assertJsonStructure(['data' => [['key', 'module', 'description', 'view_permissions', 'permissions' => [['id', 'name', 'module', 'guard_name']]]]]);

        $areas = collect($response->json('data'))->keyBy('key');

        // Every seeded permission sits in an area; nothing falls through to "Other".
        $this->assertFalse($areas->has('other'), 'Unplaced permissions: '.json_encode($areas->get('other')));

        foreach ($areas as $key => $area) {
            $all = collect($area['permissions'])->pluck('name');
            // Each area has a read-only level and something more for full control.
            $this->assertNotEmpty($area['view_permissions'], "{$key} has no view level");
            $this->assertGreaterThan(count($area['view_permissions']), $all->count(), "{$key} has no full-control level");
            $this->assertEmpty(array_diff($area['view_permissions'], $all->all()), "{$key} view is not part of full control");
        }

        // Former single-level sections now have both levels.
        $this->assertSame(['view settings'], $areas['settings']['view_permissions']);
        $this->assertContains('manage settings', collect($areas['settings']['permissions'])->pluck('name'));
        $this->assertContains('monitor security events', collect($areas['audit']['permissions'])->pluck('name'));
        $this->assertSame(['view identity verifications'], $areas['identity']['view_permissions']);
    }

    public function test_view_settings_and_view_data_are_read_only(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findOrCreate('settings-reader', 'web')->syncPermissions(['view settings', 'view data']);
        $reader = User::factory()->create(['status' => 'active']);
        $reader->assignRole($role);
        $token = $reader->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/settings')->assertOk();
        $this->withToken($token)->putJson('/api/settings', ['general' => ['platform_name' => 'X']])->assertForbidden();
        $this->withToken($token)->getJson('/api/data-management/deleted')->assertOk();
        $this->withToken($token)->getJson('/api/data-management/archives')->assertOk();
        $this->withToken($token)->getJson('/api/data-management/export?type=users')->assertForbidden();
    }
}
