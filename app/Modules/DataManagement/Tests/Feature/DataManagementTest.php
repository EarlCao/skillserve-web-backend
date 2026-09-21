<?php

namespace App\Modules\DataManagement\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A 18.1 export, A 18.2 archive and A 18.3 restore. (Deleted records, A 18.4,
 * are covered by Reviews\Tests\Feature\ReviewRemovalTest.)
 */
class DataManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['manage data', 'export system data', 'archive records', 'restore archived records'];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    public function test_a_service_is_archived_listed_and_restored_to_its_previous_state(): void
    {
        $token = $this->token(self::PERMISSIONS);
        $service = $this->service('published');

        $this->withToken($token)
            ->postJson('/api/data-management/archives', ['resource_type' => 'services', 'resource_id' => $service->id])
            ->assertSuccessful();
        $this->assertSame('archived', $service->fresh()->status);

        $archive = $this->withToken($token)
            ->getJson('/api/data-management/archives')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->json('data.0');

        $this->withToken($token)
            ->postJson("/api/data-management/archives/{$archive['id']}/restore")
            ->assertOk();
        $this->assertSame('published', $service->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'data_management', 'description' => 'Record restored']);
    }

    public function test_only_supported_types_can_be_archived(): void
    {
        $this->withToken($this->token(self::PERMISSIONS))
            ->postJson('/api/data-management/archives', ['resource_type' => 'users', 'resource_id' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resource_type');
    }

    public function test_services_export_as_csv(): void
    {
        $this->service('published', 'Aircon Cleaning');

        $response = $this->withToken($this->token(self::PERMISSIONS))
            ->get('/api/data-management/export?type=services')
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Aircon Cleaning', $response->streamedContent());
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $token = $this->token(['manage data']);
        $service = $this->service('published');

        $this->withToken($token)->get('/api/data-management/export?type=services')->assertForbidden();
        $this->withToken($token)
            ->postJson('/api/data-management/archives', ['resource_type' => 'services', 'resource_id' => $service->id])
            ->assertForbidden();
        $this->assertSame('published', $service->fresh()->status);
    }

    private function token(array $permissions): string
    {
        $role = Role::create(['name' => 'data-'.Str::random(6)]);
        $role->syncPermissions($permissions);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('test')->plainTextToken;
    }

    private function service(string $status, string $title = 'Deep Cleaning'): Service
    {
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'Data Provider']);
        $category = ServiceCategory::create(['name' => 'Data '.Str::random(6), 'status' => 'enabled']);

        return Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => $title,
            'price' => 100,
            'status' => $status,
            'approval_status' => 'approved',
        ]);
    }
}
