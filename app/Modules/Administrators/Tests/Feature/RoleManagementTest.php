<?php

namespace App\Modules\Administrators\Tests\Feature;

use App\Models\User;
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

    public function test_permission_matrix_groups_permissions_by_module(): void
    {
        [, $token] = $this->actingAdministrator();

        Permission::findOrCreate('manage administrators');
        Permission::findOrCreate('view reports');
        Permission::findOrCreate('manage providers');

        $response = $this->withToken($token)
            ->getJson('/api/permissions')
            ->assertOk();

        $modules = collect($response->json('data'))->keyBy('module');

        $this->assertContains('manage administrators', collect($modules->get('Administrators')['permissions'])->pluck('name')->all());
        $this->assertContains('manage providers', collect($modules->get('Providers')['permissions'])->pluck('name')->all());
        $this->assertContains('view reports', collect($modules->get('Reports')['permissions'])->pluck('name')->all());
        $this->assertNotNull($modules->get('Support'));

        $response
            ->assertJsonStructure([
                'data' => [['module', 'permissions' => [['id', 'name', 'module', 'guard_name']]]],
            ]);
    }
}
