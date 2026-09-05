<?php

namespace App\Modules\Dashboard\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_requires_the_dashboard_permission(): void
    {
        $user = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/dashboard')
            ->assertForbidden();
    }

    public function test_dashboard_returns_platform_summaries_and_analytics(): void
    {
        [$token] = $this->actingAdministrator();
        User::factory()->count(2)->create(['user_type' => 'customer', 'status' => 'active']);
        User::factory()->create(['user_type' => 'provider', 'status' => 'suspended']);

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.user_summary.total_clients', 2)
            ->assertJsonPath('data.user_summary.total_providers', 1)
            ->assertJsonStructure([
                'data' => [
                    'user_summary',
                    'service_summary',
                    'booking_summary',
                    'verification_summary',
                    'reports_summary',
                    'recent_activities',
                    'analytics' => ['monthly_activity', 'booking_statuses', 'user_statuses'],
                ],
            ]);
    }

    /** @return array{0: string} */
    private function actingAdministrator(): array
    {
        $user = User::factory()->create([
            'email' => 'dashboard-admin@skillserve.test',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $role = Role::findOrCreate('dashboard-admin');
        $role->syncPermissions([Permission::findOrCreate('view dashboard')]);
        $user->assignRole($role);

        return [$user->createToken('test')->plainTextToken];
    }
}
