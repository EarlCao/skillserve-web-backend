<?php

namespace App\Modules\Administrators\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdministratorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Create an administrator account.
     */
    private function createAdministrator(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('password123'),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'name' => 'Jane Doe',
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Create an actor with the "manage administrators" permission and return
     * its Sanctum token.
     */
    private function actingAdministrator(string $roleName = 'admin'): array
    {
        Permission::findOrCreate('manage administrators');
        $role = Role::findOrCreate($roleName);
        $role->givePermissionTo('manage administrators');

        $user = $this->createAdministrator(['email' => 'manager@skillserve.test']);
        $user->assignRole($roleName);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson('/api/administrators')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_administrator_without_permission_gets_403(): void
    {
        // The permission exists in the catalog, but this user has no roles.
        Permission::findOrCreate('manage administrators');

        $user = $this->createAdministrator();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/administrators')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_index_returns_paginated_envelope_with_search_filter_and_sort(): void
    {
        [$actor, $token] = $this->actingAdministrator();

        $target = $this->createAdministrator([
            'email' => 'zoe.search@skillserve.test',
            'first_name' => 'Zoe',
            'last_name' => 'Search',
            'name' => 'Zoe Search',
            'status' => 'inactive',
            'created_by' => $actor->id,
        ]);
        $target->assignRole('admin');

        $this->createAdministrator([
            'email' => 'other@skillserve.test',
            'first_name' => 'Other',
            'last_name' => 'Person',
            'name' => 'Other Person',
        ]);

        // Search by name.
        $this->withToken($token)
            ->getJson('/api/administrators?search=zoe&status=inactive&role=admin&sort=name&direction=asc&per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.email', 'zoe.search@skillserve.test')
            ->assertJsonPath('data.0.status', 'inactive')
            ->assertJsonPath('data.0.roles', ['admin'])
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'first_name', 'last_name', 'name', 'email', 'status', 'roles', 'permissions', 'last_login_at', 'created_by', 'created_at', 'updated_at'],
                ],
                'meta' => ['pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to']],
            ]);
    }

    public function test_index_excludes_platform_users_without_roles(): void
    {
        [, $token] = $this->actingAdministrator();

        // A role-bearing administrator belongs to the listing.
        $admin = $this->createAdministrator(['email' => 'real.admin@skillserve.test']);
        $admin->assignRole('admin');

        // A plain platform user (no roles) must never appear as an administrator.
        $this->createAdministrator(['email' => 'plain.user@skillserve.test']);

        $response = $this->withToken($token)
            ->getJson('/api/administrators')
            ->assertOk()
            ->assertJsonPath('success', true)
            // The acting manager + real.admin are both role-bearing.
            ->assertJsonPath('meta.pagination.total', 2);

        $emails = collect($response->json('data'))->pluck('email');

        $this->assertContains('real.admin@skillserve.test', $emails);
        $this->assertNotContains('plain.user@skillserve.test', $emails);
    }

    public function test_store_creates_an_active_administrator_with_hashed_password_and_role(): void
    {
        [, $token] = $this->actingAdministrator();
        Permission::findOrCreate('view reports');

        $this->withToken($token)
            ->postJson('/api/administrators', [
                'first_name' => 'New',
                'last_name' => 'Admin',
                'email' => 'new.admin@skillserve.test',
                'password' => 'Secret#2026',
                'password_confirmation' => 'Secret#2026',
                'role' => 'admin',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'new.admin@skillserve.test')
            ->assertJsonPath('data.name', 'New Admin')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles', ['admin']);

        // The account can actually sign in (password was hashed, role works).
        $this->postJson('/api/auth/login', [
            'email' => 'new.admin@skillserve.test',
            'password' => 'Secret#2026',
        ])->assertOk();
    }

    public function test_store_rejects_duplicate_email_and_weak_password(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->createAdministrator(['email' => 'taken@skillserve.test']);

        $this->withToken($token)
            ->postJson('/api/administrators', [
                'first_name' => 'New',
                'last_name' => 'Admin',
                'email' => 'taken@skillserve.test',
                'password' => 'short',
                'password_confirmation' => 'short',
                'role' => 'admin',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    public function test_store_requires_a_valid_role(): void
    {
        [, $token] = $this->actingAdministrator();

        $this->withToken($token)
            ->postJson('/api/administrators', [
                'first_name' => 'New',
                'last_name' => 'Admin',
                'email' => 'new.admin@skillserve.test',
                'password' => 'Secret#2026',
                'password_confirmation' => 'Secret#2026',
                'role' => 'does-not-exist',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role']]);
    }

    public function test_show_returns_a_single_administrator(): void
    {
        [, $token] = $this->actingAdministrator();
        $administrator = $this->createAdministrator();

        $this->withToken($token)
            ->getJson("/api/administrators/{$administrator->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $administrator->id);
    }

    public function test_update_modifies_profile_email_and_role(): void
    {
        [, $token] = $this->actingAdministrator();
        Role::findOrCreate('admin');
        $administrator = $this->createAdministrator(['email' => 'before@skillserve.test']);

        $this->withToken($token)
            ->putJson("/api/administrators/{$administrator->id}", [
                'first_name' => 'Jane',
                'last_name' => 'Roe',
                'email' => 'after@skillserve.test',
                'role' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane Roe')
            ->assertJsonPath('data.email', 'after@skillserve.test')
            ->assertJsonPath('data.roles', ['admin']);
    }

    public function test_update_rejects_duplicate_email(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->createAdministrator(['email' => 'other@skillserve.test']);
        $administrator = $this->createAdministrator(['email' => 'keep@skillserve.test']);

        $this->withToken($token)
            ->putJson("/api/administrators/{$administrator->id}", [
                'email' => 'other@skillserve.test',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_update_can_change_status_and_still_applies_self_deactivation_guard(): void
    {
        [$actor, $token] = $this->actingAdministrator();

        // Deactivating yourself through the update endpoint is blocked too.
        $this->withToken($token)
            ->putJson("/api/administrators/{$actor->id}", ['status' => 'inactive'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);

        $target = $this->createAdministrator(['email' => 'status.via.update@skillserve.test']);

        $this->withToken($token)
            ->putJson("/api/administrators/{$target->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_super_administrator_role_is_fixed_and_profile_is_not_partially_saved(): void
    {
        [, $token] = $this->actingAdministrator();
        Role::findOrCreate('super-admin');

        $superAdmin = $this->createAdministrator([
            'email' => 'fixed.super@skillserve.test',
            'first_name' => 'Fixed',
            'last_name' => 'Super',
            'name' => 'Fixed Super',
        ]);
        $superAdmin->assignRole('super-admin');

        // Changing the super administrator role away is rejected.
        $this->withToken($token)
            ->putJson("/api/administrators/{$superAdmin->id}", [
                'first_name' => 'Changed',
                'last_name' => 'Super',
                'email' => 'fixed.super@skillserve.test',
                'role' => 'admin',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['role']]);

        // The rejection is atomic — the profile was not partially saved.
        $superAdmin->refresh();
        $this->assertSame('Fixed Super', $superAdmin->name);
        $this->assertTrue($superAdmin->hasRole('super-admin'));
    }

    public function test_cannot_deactivate_your_own_account(): void
    {
        [$actor, $token] = $this->actingAdministrator();

        $this->withToken($token)
            ->patchJson("/api/administrators/{$actor->id}/status", ['status' => 'inactive'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_cannot_deactivate_the_last_super_administrator(): void
    {
        [, $token] = $this->actingAdministrator();

        // A super administrator who is the only active one.
        Role::findOrCreate('super-admin');
        $superAdmin = $this->createAdministrator(['email' => 'super@skillserve.test']);
        $superAdmin->assignRole('super-admin');

        $this->withToken($token)
            ->patchJson("/api/administrators/{$superAdmin->id}/status", ['status' => 'inactive'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_deactivating_an_administrator_blocks_login_and_reactivating_restores_it(): void
    {
        [, $token] = $this->actingAdministrator();
        $target = $this->createAdministrator(['email' => 'target@skillserve.test']);

        $this->withToken($token)
            ->patchJson("/api/administrators/{$target->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        Auth::forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => 'target@skillserve.test',
            'password' => 'password123',
        ])->assertStatus(403)->assertJsonPath('success', false);

        // Reactivate — login works again and last_login_at is recorded.
        $this->withToken($token)
            ->patchJson("/api/administrators/{$target->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson('/api/auth/login', [
            'email' => 'target@skillserve.test',
            'password' => 'password123',
        ])->assertOk();

        $this->assertNotNull($target->fresh()->last_login_at);
    }

    public function test_administrator_actions_write_audit_log_entries(): void
    {
        [, $token] = $this->actingAdministrator();

        $this->withToken($token)
            ->postJson('/api/administrators', [
                'first_name' => 'Audited',
                'last_name' => 'Admin',
                'email' => 'audited@skillserve.test',
                'password' => 'Secret#2026',
                'password_confirmation' => 'Secret#2026',
                'role' => 'admin',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('activity_log', ['description' => 'administrator_created']);

        $created = User::where('email', 'audited@skillserve.test')->firstOrFail();

        $this->withToken($token)
            ->patchJson("/api/administrators/{$created->id}/status", ['status' => 'inactive'])
            ->assertOk();

        $this->assertDatabaseHas('activity_log', ['description' => 'administrator_status_changed']);

        // The entry carries the actor + subject, and never the credentials.
        $entry = Activity::where('description', 'administrator_created')->latest('id')->firstOrFail();
        $this->assertNotNull($entry->causer);
        $this->assertSame($created->id, $entry->subject_id);
        $this->assertArrayNotHasKey('password', $entry->properties->all()['created'] ?? []);
        $this->assertArrayNotHasKey('password_confirmation', $entry->properties->all()['created'] ?? []);
    }
}
