<?php

namespace App\Modules\Services\Tests\Feature;

use App\Models\User;
use App\Modules\ClientCommunication\Events\ClientNotificationCreated;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
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

    private function service(ServiceCategory $category, array $attributes = []): Service
    {
        return Service::create(array_merge([
            'provider_id' => $this->provider()->id,
            'category_id' => $category->id,
            'title' => 'Aircon Cleaning',
            'price' => 1500,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'draft',
            'approval_status' => 'pending',
        ], $attributes));
    }

    private function providerNotifications(Service $service): Collection
    {
        return $service->provider->user->notifications()->get();
    }

    public function test_administrators_cannot_create_services(): void
    {
        [, $token] = $this->actingServices(['create services']);

        $this->withToken($token)
            ->postJson('/api/services', ['title' => 'Repair', 'category_id' => $this->category('Home Services')->id])
            ->assertStatus(405);
    }

    public function test_administrators_cannot_change_provider_owned_fields(): void
    {
        [, $token] = $this->actingServices(['edit services']);
        $service = $this->service($this->category('Cleaning'));

        $this->withToken($token)
            ->putJson("/api/services/{$service->id}", [
                'provider_id' => $this->provider()->id,
                'price' => 1,
                'price_type' => 'hourly',
                'currency' => 'USD',
                'duration' => '1 hour',
                'location' => 'Elsewhere',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['provider_id', 'price', 'price_type', 'currency', 'duration', 'location']]);

        $this->assertDatabaseHas('services', ['id' => $service->id, 'price' => 1500, 'price_type' => 'fixed']);
    }

    public function test_subcategory_must_belong_to_the_selected_category(): void
    {
        [, $token] = $this->actingServices(['edit services']);
        $firstCategory = $this->category('First Category');
        $secondCategory = $this->category('Second Category');
        $subcategory = ServiceSubcategory::create(['category_id' => $secondCategory->id, 'name' => 'Wrong Parent', 'status' => 'enabled']);
        $service = $this->service($firstCategory);

        $this->withToken($token)
            ->putJson("/api/services/{$service->id}", ['subcategory_id' => $subcategory->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['subcategory_id']]);
    }

    public function test_administrator_edit_notifies_the_provider_of_changed_fields(): void
    {
        [, $token] = $this->actingServices(['edit services']);
        $service = $this->service($this->category('Cleaning'), ['approval_status' => 'approved', 'status' => 'published']);

        $this->withToken($token)
            ->putJson("/api/services/{$service->id}", ['title' => 'Aircon Deep Cleaning', 'description' => 'Split-type units.'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Aircon Deep Cleaning');

        $notification = $this->providerNotifications($service)->sole();
        $this->assertSame('service_moderation', $notification->data['type']);
        $this->assertSame('updated', $notification->data['action']);
        $this->assertStringContainsString('title, description', $notification->data['message']);
    }

    public function test_administrator_edit_without_changes_does_not_notify(): void
    {
        [, $token] = $this->actingServices(['edit services']);
        $service = $this->service($this->category('Cleaning'));

        $this->withToken($token)
            ->putJson("/api/services/{$service->id}", ['title' => $service->title])
            ->assertOk();

        $this->assertCount(0, $this->providerNotifications($service));
    }

    public function test_moderation_notifications_are_pushed_to_the_provider_in_realtime(): void
    {
        Event::fake([ClientNotificationCreated::class]);
        [, $token] = $this->actingServices(['approve services']);
        $service = $this->service($this->category('Realtime'));
        $service->provider->user->update(['role_id' => 3]);

        $this->withToken($token)->patchJson("/api/services/{$service->id}/approve")->assertOk();

        Event::assertDispatched(
            ClientNotificationCreated::class,
            fn (ClientNotificationCreated $event) => $event->userId === $service->provider->user_id,
        );
    }

    public function test_every_moderation_action_notifies_the_provider(): void
    {
        [, $token] = $this->actingServices(['approve services', 'reject services', 'edit services', 'feature services', 'delete services']);
        $category = $this->category('Moderation');

        $approved = $this->service($category);
        $this->withToken($token)->patchJson("/api/services/{$approved->id}/approve")->assertOk();

        $rejected = $this->service($category);
        $this->withToken($token)->patchJson("/api/services/{$rejected->id}/reject", ['reason' => 'Blurry photos'])->assertOk();

        $hidden = $this->service($category);
        $this->withToken($token)->patchJson("/api/services/{$hidden->id}/hide", ['is_hidden' => true])->assertOk();

        $featured = $this->service($category);
        $this->withToken($token)->patchJson("/api/services/{$featured->id}/feature", ['is_featured' => true])->assertOk();

        $deleted = $this->service($category);
        $this->withToken($token)->deleteJson("/api/services/{$deleted->id}")->assertSuccessful();

        $this->assertSame('approved', $this->providerNotifications($approved)->sole()->data['action']);
        $this->assertSame('Blurry photos', $this->providerNotifications($rejected)->sole()->data['reason']);
        $this->assertSame('hidden', $this->providerNotifications($hidden)->sole()->data['action']);
        $this->assertSame('featured', $this->providerNotifications($featured)->sole()->data['action']);
        $this->assertSame('deleted', $this->providerNotifications($deleted)->sole()->data['action']);
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
