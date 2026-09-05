<?php

namespace App\Modules\Audit\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_audit_logs_require_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/audit-logs')
            ->assertForbidden();
    }

    public function test_audit_logs_support_search_and_login_view(): void
    {
        [$admin, $token] = $this->actingAuditor(['view audit logs', 'view login activity']);
        activity('authentication')->causedBy($admin)->performedOn($admin)->log('administrator_logged_in');
        activity('users')->causedBy($admin)->performedOn($admin)->log('user_updated');

        $this->withToken($token)
            ->getJson('/api/audit-logs?view=login&search=logged_in')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'administrator_logged_in');
    }

    public function test_security_view_requires_security_permission(): void
    {
        [, $token] = $this->actingAuditor(['view audit logs']);

        $this->withToken($token)
            ->getJson('/api/audit-logs?view=security')
            ->assertForbidden();
    }

    public function test_administrator_filter_endpoint_returns_causers(): void
    {
        [$admin, $token] = $this->actingAuditor(['view audit logs']);
        activity('users')->causedBy($admin)->performedOn($admin)->log('user_updated');

        $this->withToken($token)
            ->getJson('/api/audit-logs/administrators')
            ->assertOk()
            ->assertJsonPath('data.0.id', $admin->id);
    }

    /** @param array<int, string> $permissions */
    private function actingAuditor(array $permissions): array
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::findOrCreate('audit-admin');
        $role->syncPermissions($permissions);
        $user = User::factory()->create([
            'email' => 'audit-admin@skillserve.test',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return [$user, $user->createToken('test')->plainTextToken];
    }
}
