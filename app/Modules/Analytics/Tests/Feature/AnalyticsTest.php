<?php

namespace App\Modules\Analytics\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_report_requires_authentication(): void
    {
        $this->getJson('/api/analytics/reports?type=users')->assertUnauthorized();
    }

    public function test_report_requires_the_view_permission(): void
    {
        $user = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/analytics/reports?type=users')
            ->assertForbidden();
    }

    public function test_user_report_returns_platform_users_with_counts(): void
    {
        [$token] = $this->actingAnalyst(['view analytics']);
        User::factory()->count(3)->create(['user_type' => 'customer', 'status' => 'active']);
        User::factory()->create(['user_type' => 'provider', 'status' => 'suspended']);

        $this->withToken($token)
            ->getJson('/api/analytics/reports?type=users')
            ->assertOk()
            ->assertJsonPath('message', 'Report generated.')
            ->assertJsonCount(4, 'data');
    }

    public function test_unsupported_report_type_is_rejected(): void
    {
        [$token] = $this->actingAnalyst(['view analytics']);

        $this->withToken($token)
            ->getJson('/api/analytics/reports?type=unknown')
            ->assertUnprocessable();
    }

    public function test_export_requires_the_export_permission(): void
    {
        [$token] = $this->actingAnalyst(['view analytics']);

        $this->withToken($token)
            ->get('/api/analytics/reports/export?type=users')
            ->assertForbidden();
    }

    public function test_export_streams_a_csv(): void
    {
        [$token] = $this->actingAnalyst(['view analytics', 'export analytics']);

        $this->withToken($token)
            ->get('/api/analytics/reports/export?type=users')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_export_neutralizes_formula_injection(): void
    {
        [$token] = $this->actingAnalyst(['view analytics', 'export analytics']);
        User::factory()->create([
            'name' => '=HYPERLINK("http://evil","x")',
            'email' => 'formula@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        $response = $this->withToken($token)
            ->get('/api/analytics/reports/export?type=users')
            ->assertOk();

        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: string}
     */
    private function actingAnalyst(array $permissions): array
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $role = Role::findOrCreate('analyst');
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'email' => 'analyst@skillserve.test',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return [$user->createToken('test')->plainTextToken];
    }
}
