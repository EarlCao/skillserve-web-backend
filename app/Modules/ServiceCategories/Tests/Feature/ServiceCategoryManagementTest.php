<?php

namespace App\Modules\ServiceCategories\Tests\Feature;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ServiceCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Create an actor with the "manage service categories" permission and
     * return its Sanctum token.
     */
    private function actingAdministrator(): array
    {
        Permission::findOrCreate('manage service categories');
        $role = Role::findOrCreate('admin');
        $role->givePermissionTo('manage service categories');

        $user = User::factory()->create([
            'password' => Hash::make('password123'),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'name' => 'Jane Doe',
            'status' => 'active',
        ]);
        $user->assignRole('admin');

        return [$user, $user->createToken('test')->plainTextToken];
    }

    /**
     * Create a service category row directly.
     */
    private function createCategory(array $attributes = []): ServiceCategory
    {
        return ServiceCategory::create(array_merge([
            'name' => 'Home Maintenance',
            'description' => 'Plumbing, electrical and painting services.',
            'status' => 'enabled',
        ], $attributes));
    }

    /**
     * Create a subcategory row directly under a category.
     */
    private function createSubcategory(ServiceCategory $category, array $attributes = []): ServiceSubcategory
    {
        return ServiceSubcategory::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Plumbing',
            'status' => 'enabled',
        ], $attributes));
    }

    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson('/api/service-categories')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_administrator_without_permission_gets_403(): void
    {
        Permission::findOrCreate('manage service categories');

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/service-categories')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_index_returns_paginated_envelope_with_search_filter_and_sort(): void
    {
        [, $token] = $this->actingAdministrator();

        $this->createCategory([
            'name' => 'Zoo Cleaning',
            'description' => 'Cleaning for animal enclosures.',
            'status' => 'disabled',
        ]);
        $this->createCategory(['name' => 'Automotive']);

        $this->withToken($token)
            ->getJson('/api/service-categories?search=cleaning&status=disabled&sort=name&direction=asc&per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'Zoo Cleaning')
            ->assertJsonPath('data.0.status', 'disabled')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'description', 'status', 'subcategories_count', 'created_by', 'created_at', 'updated_at'],
                ],
                'meta' => ['pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to']],
            ]);
    }

    public function test_index_includes_subcategory_counts(): void
    {
        [, $token] = $this->actingAdministrator();

        $category = $this->createCategory();
        $this->createSubcategory($category);
        $this->createSubcategory($category, ['name' => 'Electrical']);

        $this->withToken($token)
            ->getJson('/api/service-categories')
            ->assertOk()
            ->assertJsonPath('data.0.subcategories_count', 2);
    }

    public function test_store_creates_a_category(): void
    {
        [, $token] = $this->actingAdministrator();

        $this->withToken($token)
            ->postJson('/api/service-categories', [
                'name' => 'Landscaping',
                'description' => 'Garden and lawn care.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Landscaping')
            ->assertJsonPath('data.status', 'enabled');

        $this->assertDatabaseHas('service_categories', ['name' => 'Landscaping']);
    }

    public function test_store_rejects_duplicate_name_and_invalid_status(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->createCategory(['name' => 'Home Maintenance']);

        $this->withToken($token)
            ->postJson('/api/service-categories', [
                'name' => 'Home Maintenance',
                'status' => 'sometimes-broken',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name', 'status']]);
    }

    public function test_show_returns_category_with_subcategories(): void
    {
        [, $token] = $this->actingAdministrator();

        $category = $this->createCategory();
        $this->createSubcategory($category);
        $this->createSubcategory($category, ['name' => 'Electrical']);

        $this->withToken($token)
            ->getJson("/api/service-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonCount(2, 'data.subcategories')
            ->assertJsonStructure(['data' => ['subcategories' => ['*' => ['id', 'category_id', 'name', 'description', 'status']]]]);
    }

    public function test_update_modifies_category_details(): void
    {
        [, $token] = $this->actingAdministrator();
        $category = $this->createCategory();

        $this->withToken($token)
            ->putJson("/api/service-categories/{$category->id}", [
                'name' => 'Home Maintenance & Repair',
                'description' => 'Updated description.',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Home Maintenance & Repair')
            ->assertJsonPath('data.description', 'Updated description.');
    }

    public function test_update_rejects_duplicate_name(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->createCategory(['name' => 'Automotive']);
        $category = $this->createCategory(['name' => 'Landscaping']);

        $this->withToken($token)
            ->putJson("/api/service-categories/{$category->id}", ['name' => 'Automotive'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_status_can_be_toggled(): void
    {
        [, $token] = $this->actingAdministrator();
        $category = $this->createCategory();

        $this->withToken($token)
            ->patchJson("/api/service-categories/{$category->id}/status", ['status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->assertSame('disabled', $category->fresh()->status);

        // The record is never physically deleted by disabling.
        $this->assertDatabaseHas('service_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_destroy_is_blocked_when_category_has_subcategories(): void
    {
        [, $token] = $this->actingAdministrator();
        $category = $this->createCategory();
        $this->createSubcategory($category);

        $this->withToken($token)
            ->deleteJson("/api/service-categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['subcategories']]);

        // Nothing was deleted.
        $this->assertNull($category->fresh()->deleted_at);
    }

    public function test_destroy_soft_deletes_an_empty_category(): void
    {
        [, $token] = $this->actingAdministrator();
        $category = $this->createCategory();

        $this->withToken($token)
            ->deleteJson("/api/service-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Service category deleted.');

        $this->assertNotNull($category->fresh()->deleted_at);
    }

    public function test_subcategory_crud(): void
    {
        [, $token] = $this->actingAdministrator();
        $category = $this->createCategory();

        // Create.
        $this->withToken($token)
            ->postJson("/api/service-categories/{$category->id}/subcategories", [
                'name' => 'Plumbing',
                'description' => 'Pipe repair.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Plumbing')
            ->assertJsonPath('data.category_id', $category->id);

        $subcategory = ServiceSubcategory::where('name', 'Plumbing')->firstOrFail();

        // Duplicate name within the same category is rejected.
        $this->withToken($token)
            ->postJson("/api/service-categories/{$category->id}/subcategories", ['name' => 'Plumbing'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']]);

        // The same name is allowed under a different category.
        $other = $this->createCategory(['name' => 'Another Category']);
        $this->withToken($token)
            ->postJson("/api/service-categories/{$other->id}/subcategories", ['name' => 'Plumbing'])
            ->assertStatus(201);

        // Update.
        $this->withToken($token)
            ->putJson("/api/service-categories/{$category->id}/subcategories/{$subcategory->id}", [
                'name' => 'Emergency Plumbing',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Emergency Plumbing');

        // Delete.
        $this->withToken($token)
            ->deleteJson("/api/service-categories/{$category->id}/subcategories/{$subcategory->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Subcategory deleted.');

        $this->assertNotNull($subcategory->fresh()->deleted_at);
    }

    public function test_subcategory_cannot_be_managed_through_the_wrong_category(): void
    {
        [, $token] = $this->actingAdministrator();
        $category = $this->createCategory();
        $other = $this->createCategory(['name' => 'Other Category']);
        $subcategory = $this->createSubcategory($category);

        // Editing the subcategory through a different parent category → 404.
        $this->withToken($token)
            ->putJson("/api/service-categories/{$other->id}/subcategories/{$subcategory->id}", [
                'name' => 'Hijacked',
            ])
            ->assertStatus(404);

        // Deleting it through the wrong category → 404.
        $this->withToken($token)
            ->deleteJson("/api/service-categories/{$other->id}/subcategories/{$subcategory->id}")
            ->assertStatus(404);

        // The record was not touched.
        $this->assertSame('Plumbing', $subcategory->fresh()->name);
    }

    public function test_actions_write_audit_log_entries(): void
    {
        [, $token] = $this->actingAdministrator();

        $this->withToken($token)
            ->postJson('/api/service-categories', ['name' => 'Audited Category'])
            ->assertStatus(201);

        $this->assertDatabaseHas('activity_log', ['description' => 'service_category_created']);

        $category = ServiceCategory::where('name', 'Audited Category')->firstOrFail();

        $this->withToken($token)
            ->patchJson("/api/service-categories/{$category->id}/status", ['status' => 'disabled'])
            ->assertOk();

        $this->assertDatabaseHas('activity_log', ['description' => 'service_category_status_changed']);

        $this->withToken($token)
            ->postJson("/api/service-categories/{$category->id}/subcategories", ['name' => 'Sub'])
            ->assertStatus(201);

        $this->assertDatabaseHas('activity_log', ['description' => 'service_subcategory_created']);

        // The entry carries the actor + subject.
        $entry = Activity::where('description', 'service_category_created')->latest('id')->firstOrFail();
        $this->assertNotNull($entry->causer);
        $this->assertSame($category->id, $entry->subject_id);
        $this->assertSame('service_categories', $entry->log_name);
    }
}
