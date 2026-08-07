<?php

namespace App\Modules\Users\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Create a platform user (no roles — user management scope).
     */
    private function createUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('password123'),
            'first_name' => 'Alice',
            'last_name' => 'Customer',
            'name' => 'Alice Customer',
            'status' => 'active',
            'user_type' => 'customer',
        ], $attributes));
    }

    /**
     * Create an actor with the "manage users" permission and return its
     * Sanctum token.
     */
    private function actingManager(string $roleName = 'admin'): array
    {
        Permission::findOrCreate('manage users');
        $role = Role::findOrCreate($roleName);
        $role->givePermissionTo('manage users');

        $user = User::factory()->create([
            'email' => 'manager@skillserve.test',
            'password' => Hash::make('password123'),
            'name' => 'Manager',
            'status' => 'active',
        ]);
        $user->assignRole($roleName);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson('/api/users')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_user_without_permission_gets_403(): void
    {
        Permission::findOrCreate('manage users');

        $user = $this->createUser(['email' => 'no-perm@skillserve.test']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/users')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_index_returns_paginated_envelope_with_search_filter_and_sort(): void
    {
        [, $token] = $this->actingManager();

        $target = $this->createUser([
            'email' => 'zoe.search@skillserve.test',
            'first_name' => 'Zoe',
            'last_name' => 'Search',
            'name' => 'Zoe Search',
            'status' => 'suspended',
            'phone' => '+1 555 0100',
        ]);
        $this->createUser([
            'email' => 'unverified@skillserve.test',
            'first_name' => 'Una',
            'last_name' => 'Verified',
            'name' => 'Una Verified',
        ])->markEmailAsUnverified();

        // Search by name + status filter + sort.
        $this->withToken($token)
            ->getJson('/api/users?search=zoe&status=suspended&sort=name&direction=asc&per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.email', 'zoe.search@skillserve.test')
            ->assertJsonPath('data.0.status', 'suspended')
            ->assertJsonPath('data.0.user_type', 'customer')
            ->assertJsonPath('data.0.verification', 'verified')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'first_name', 'last_name', 'name', 'email', 'user_type',
                        'phone', 'status', 'verification', 'last_login_at', 'created_at', 'updated_at',
                    ],
                ],
                'meta' => ['pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to']],
            ]);

        // Search by user ID.
        $this->withToken($token)
            ->getJson("/api/users?search={$target->id}")
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $target->id);

        // Verification filter.
        $this->withToken($token)
            ->getJson('/api/users?verification=unverified')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.email', 'unverified@skillserve.test');
    }

    public function test_index_excludes_administrator_accounts(): void
    {
        [, $token] = $this->actingManager();

        Role::findOrCreate('admin');
        $admin = $this->createUser(['email' => 'admin.user@skillserve.test']);
        $admin->assignRole('admin');

        $this->withToken($token)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_show_returns_a_single_user_profile(): void
    {
        [, $token] = $this->actingManager();
        $user = $this->createUser();

        $this->withToken($token)
            ->getJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.summary.services_count', 0)
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'email', 'user_type', 'phone', 'address', 'birthday',
                    'status', 'verification', 'summary' => ['services_count', 'bookings_count', 'ratings_count', 'reviews_count', 'recent_activity'],
                ],
            ]);
    }

    public function test_show_returns_404_for_a_deleted_user(): void
    {
        [, $token] = $this->actingManager();
        $user = $this->createUser();
        $user->delete();

        $this->withToken($token)
            ->getJson("/api/users/{$user->id}")
            ->assertStatus(404);
    }

    public function test_update_modifies_profile_and_keeps_name_in_sync(): void
    {
        [, $token] = $this->actingManager();
        $user = $this->createUser(['email' => 'before@skillserve.test']);

        $this->withToken($token)
            ->putJson("/api/users/{$user->id}", [
                'first_name' => 'Zoe',
                'last_name' => 'Roe',
                'email' => 'after@skillserve.test',
                'phone' => '+1 555 0199',
                'address' => '456 Oak Ave',
                'birthday' => '1995-04-12',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Zoe Roe')
            ->assertJsonPath('data.email', 'after@skillserve.test')
            ->assertJsonPath('data.phone', '+1 555 0199')
            ->assertJsonPath('data.birthday', '1995-04-12');
    }

    public function test_update_rejects_duplicate_email_and_future_birthday(): void
    {
        [, $token] = $this->actingManager();
        $this->createUser(['email' => 'other@skillserve.test']);
        $user = $this->createUser(['email' => 'keep@skillserve.test']);

        $this->withToken($token)
            ->putJson("/api/users/{$user->id}", ['email' => 'other@skillserve.test'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);

        $this->withToken($token)
            ->putJson("/api/users/{$user->id}", ['birthday' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['birthday']]);
    }

    public function test_suspend_blocks_login_and_records_audit(): void
    {
        [, $token] = $this->actingManager();
        $target = $this->createUser(['email' => 'suspend.me@skillserve.test']);

        $this->withToken($token)
            ->patchJson("/api/users/{$target->id}/suspend", ['reason' => 'Suspected fraud.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Suspected fraud.');

        Auth::forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => 'suspend.me@skillserve.test',
            'password' => 'password123',
        ])->assertStatus(403)->assertJsonPath('success', false);

        $this->assertDatabaseHas('activity_log', ['description' => 'user_suspended']);
    }

    public function test_suspend_requires_a_reason(): void
    {
        [, $token] = $this->actingManager();
        $user = $this->createUser();

        $this->withToken($token)
            ->patchJson("/api/users/{$user->id}/suspend", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_activate_restores_a_suspended_account_and_clears_the_trail(): void
    {
        [, $token] = $this->actingManager();
        $user = $this->createUser();
        $user->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => 'Review.',
        ]);

        $this->withToken($token)
            ->patchJson("/api/users/{$user->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.suspended_at', null)
            ->assertJsonPath('data.suspension_reason', null);

        $this->assertDatabaseHas('activity_log', ['description' => 'user_activated']);
    }

    public function test_ban_is_terminal_and_blocks_login(): void
    {
        [, $token] = $this->actingManager();
        $target = $this->createUser(['email' => 'ban.me@skillserve.test']);

        $this->withToken($token)
            ->patchJson("/api/users/{$target->id}/ban", ['reason' => 'Policy violations.', 'duration' => 'forever'])
            ->assertOk()
            ->assertJsonPath('data.status', 'banned')
            ->assertJsonPath('data.ban_reason', 'Policy violations.')
            ->assertJsonPath('data.banned_until', null);

        Auth::forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => 'ban.me@skillserve.test',
            'password' => 'password123',
        ])->assertStatus(403)->assertJsonPath('success', false);

        // Banned accounts are terminal — they cannot be activated.
        $this->withToken($token)
            ->patchJson("/api/users/{$target->id}/activate")
            ->assertStatus(422);

        $this->assertDatabaseHas('activity_log', ['description' => 'user_banned']);
    }

    public function test_cannot_suspend_an_already_suspended_or_banned_account(): void
    {
        [, $token] = $this->actingManager();

        $suspended = $this->createUser(['status' => 'suspended']);
        $this->withToken($token)
            ->patchJson("/api/users/{$suspended->id}/suspend", ['reason' => 'Again.'])
            ->assertStatus(422);

        $banned = $this->createUser(['status' => 'banned']);
        $this->withToken($token)
            ->patchJson("/api/users/{$banned->id}/suspend", ['reason' => 'Again.'])
            ->assertStatus(422);
    }

    public function test_delete_soft_deletes_and_records_audit(): void
    {
        [, $token] = $this->actingManager();
        $target = $this->createUser(['email' => 'delete.me@skillserve.test']);

        $this->withToken($token)
            ->deleteJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertDatabaseHas('activity_log', ['description' => 'user_deleted']);

        // A deleted account cannot sign in (soft-deleted rows are excluded).
        Auth::forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => 'delete.me@skillserve.test',
            'password' => 'password123',
        ])->assertStatus(401);
    }

    public function test_cannot_delete_your_own_account(): void
    {
        [$actor, $token] = $this->actingManager();

        $this->withToken($token)
            ->deleteJson("/api/users/{$actor->id}")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['id']]);
    }

    public function test_cannot_delete_administrator_accounts(): void
    {
        [, $token] = $this->actingManager();

        Role::findOrCreate('admin');
        $admin = $this->createUser(['email' => 'admin.user@skillserve.test']);
        $admin->assignRole('admin');

        $this->withToken($token)
            ->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['id']]);

        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_administrator_accounts_are_out_of_scope_for_show_and_update(): void
    {
        [, $token] = $this->actingManager();

        Role::findOrCreate('admin');
        $admin = $this->createUser(['email' => 'admin.scope@skillserve.test']);
        $admin->assignRole('admin');

        $this->withToken($token)
            ->getJson("/api/users/{$admin->id}")
            ->assertStatus(422);

        $this->withToken($token)
            ->putJson("/api/users/{$admin->id}", ['first_name' => 'Changed', 'last_name' => 'Name'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['id']]);

        $admin->refresh();
        $this->assertSame('Alice', $admin->first_name);
    }

    public function test_banning_a_suspended_user_clears_the_suspension_state(): void
    {
        [, $token] = $this->actingManager();
        $user = $this->createUser();
        $user->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => 'Under review.',
        ]);

        $this->withToken($token)
            ->patchJson("/api/users/{$user->id}/ban", ['reason' => 'Escalated.', 'duration' => 'forever'])
            ->assertOk()
            ->assertJsonPath('data.status', 'banned')
            ->assertJsonPath('data.suspended_at', null)
            ->assertJsonPath('data.suspension_reason', null);
    }

    public function test_temporary_ban_sets_banned_until_and_auto_lifts_on_login(): void
    {
        [, $token] = $this->actingManager();
        $target = $this->createUser(['email' => 'temp.ban@skillserve.test']);

        // Freeze time so the response's banned_until matches exactly (the
        // action computes it from its own now()).
        $frozen = now();
        Carbon::setTestNow($frozen);

        try {
            $this->withToken($token)
                ->patchJson("/api/users/{$target->id}/ban", [
                    'reason' => 'Cooling-off period.',
                    'duration' => 'days',
                    'days' => 7,
                ])
                ->assertOk()
                ->assertJsonPath('data.status', 'banned')
                ->assertJsonPath('data.ban_reason', 'Cooling-off period.')
                ->assertJsonPath('data.banned_until', $frozen->copy()->addDays(7)->toIso8601String());

            // A temporary ban blocks login while the timer is running.
            Auth::forgetGuards();

            $this->postJson('/api/auth/login', [
                'email' => 'temp.ban@skillserve.test',
                'password' => 'password123',
            ])->assertStatus(403)->assertJsonPath('success', false);

            // Once the timer passes, the next login auto-lifts the ban.
            $target->update(['banned_until' => now()->subMinute()]);
            Auth::forgetGuards();

            $this->postJson('/api/auth/login', [
                'email' => 'temp.ban@skillserve.test',
                'password' => 'password123',
            ])->assertOk()->assertJsonPath('success', true);

            $this->assertSame('active', $target->fresh()->status);
            $this->assertSame('Temporary ban expired.', $target->fresh()->unban_reason);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    public function test_ban_requires_duration_and_days_when_temporary(): void
    {
        [, $token] = $this->actingManager();
        $user = $this->createUser();

        // Missing duration.
        $this->withToken($token)
            ->patchJson("/api/users/{$user->id}/ban", ['reason' => 'Test.'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['duration']]);

        // A temporary ban requires the number of days.
        $this->withToken($token)
            ->patchJson("/api/users/{$user->id}/ban", ['reason' => 'Test.', 'duration' => 'days'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['days']]);
    }

    public function test_unban_restores_account_and_records_audit(): void
    {
        [, $token] = $this->actingManager();
        $target = $this->createUser(['email' => 'unban.me@skillserve.test']);

        $this->withToken($token)
            ->patchJson("/api/users/{$target->id}/ban", [
                'reason' => 'Policy violations.',
                'duration' => 'forever',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'banned');

        $this->withToken($token)
            ->patchJson("/api/users/{$target->id}/unban", ['reason' => 'Appeal approved.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.banned_until', null)
            ->assertJsonPath('data.unban_reason', 'Appeal approved.');

        // The account can sign in again.
        Auth::forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => 'unban.me@skillserve.test',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('activity_log', ['description' => 'user_unbanned']);
    }

    public function test_cannot_unban_an_account_that_is_not_banned(): void
    {
        [, $token] = $this->actingManager();
        $user = $this->createUser();

        $this->withToken($token)
            ->patchJson("/api/users/{$user->id}/unban", ['reason' => 'Nope.'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The account is not currently banned.');
    }

    public function test_suspending_a_user_revokes_existing_tokens(): void
    {
        [, $token] = $this->actingManager();
        $target = $this->createUser(['email' => 'tokens@skillserve.test']);
        $targetToken = $target->createToken('app-session')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/users/{$target->id}/suspend", ['reason' => 'Token check.'])
            ->assertOk();

        $this->assertSame(0, $target->tokens()->count());
        $this->assertNotNull($targetToken);
    }

    public function test_user_actions_write_audit_log_entries_with_actor_and_subject(): void
    {
        [, $token] = $this->actingManager();
        $user = $this->createUser();

        $this->withToken($token)
            ->putJson("/api/users/{$user->id}", ['phone' => '+1 555 0000'])
            ->assertOk();

        $entry = Activity::where('description', 'user_updated')->latest('id')->firstOrFail();
        $this->assertNotNull($entry->causer);
        $this->assertSame($user->id, $entry->subject_id);
        $this->assertSame('users', $entry->log_name);
        $this->assertSame('+1 555 0000', $entry->properties->all()['after']['phone'] ?? null);
    }
}
