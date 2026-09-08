<?php

namespace App\Modules\Services\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function actingServices(array $permissions = []): array
    {
        $all = array_values(array_unique(array_merge(['manage services'], $permissions)));
        foreach ($all as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::findOrCreate('service-manager');
        $role->syncPermissions($all);
        $actor = User::factory()->create(['email' => 'service.manager@skillserve.test', 'status' => 'active']);
        $actor->assignRole($role);

        return [$actor, $actor->createToken('test')->plainTextToken];
    }

    private function provider(): ProviderProfile
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(6).'@skillserve.test',
            'status' => 'active',
            'user_type' => 'provider',
        ]);

        return ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Test Provider',
            'verification_status' => 'verified',
        ]);
    }

    private function category(string $name): ServiceCategory
    {
        return ServiceCategory::create(['name' => $name, 'status' => 'enabled']);
    }

    public function test_service_creation_requires_and_persists_provider_id(): void
    {
        [, $token] = $this->actingServices(['create services']);
        $category = $this->category('Home Services');
        $provider = $this->provider();

        $payload = ['title' => 'Repair', 'category_id' => $category->id];

        $this->withToken($token)
            ->postJson('/api/services', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['provider_id']]);

        $response = $this->withToken($token)
            ->postJson('/api/services', $payload + ['provider_id' => $provider->id])
            ->assertStatus(201)
            ->assertJsonPath('data.provider_id', $provider->id);

        $this->assertDatabaseHas('services', [
            'id' => $response->json('data.id'),
            'provider_id' => $provider->id,
            'category_id' => $category->id,
        ]);
    }

    public function test_subcategory_must_belong_to_the_selected_category(): void
    {
        [, $token] = $this->actingServices(['create services']);
        $firstCategory = $this->category('First Category');
        $secondCategory = $this->category('Second Category');
        $subcategory = ServiceSubcategory::create(['category_id' => $secondCategory->id, 'name' => 'Wrong Parent', 'status' => 'enabled']);
        $provider = $this->provider();

        $this->withToken($token)
            ->postJson('/api/services', [
                'title' => 'Mismatched service',
                'provider_id' => $provider->id,
                'category_id' => $firstCategory->id,
                'subcategory_id' => $subcategory->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['subcategory_id']]);
    }

    public function test_rejection_requires_and_persists_a_reason(): void
    {
        [, $token] = $this->actingServices(['reject services']);
        $category = $this->category('Review Category');
        $service = Service::create([
            'provider_id' => $this->provider()->id,
            'category_id' => $category->id,
            'title' => 'Pending Service',
            'status' => 'draft',
            'approval_status' => 'pending',
        ]);

        $this->withToken($token)
            ->patchJson("/api/services/{$service->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);

        $this->withToken($token)
            ->patchJson("/api/services/{$service->id}/reject", ['reason' => 'Incomplete listing'])
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', 'Incomplete listing');
    }
}
