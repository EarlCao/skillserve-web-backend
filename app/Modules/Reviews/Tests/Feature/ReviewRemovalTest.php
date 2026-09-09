<?php

namespace App\Modules\Reviews\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReviewRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_remove_soft_deletes_the_review_and_data_management_can_restore_it(): void
    {
        foreach (['delete reviews', 'manage deleted records', 'restore deleted records'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::create(['name' => 'review-moderator']);
        $role->syncPermissions(['delete reviews', 'manage deleted records', 'restore deleted records']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);
        $token = $admin->createToken('test')->plainTextToken;
        $review = $this->createReview();

        $this->withToken($token)
            ->deleteJson("/api/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Review soft-removed.');

        $this->assertSoftDeleted('reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'status' => 'removed']);

        $this->withToken($token)
            ->getJson('/api/data-management/deleted?resource_type=reviews&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.resource_type', 'reviews')
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.pagination.per_page', 1);

        $this->withToken($token)
            ->postJson("/api/data-management/deleted/reviews/{$review->id}/restore")
            ->assertOk();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status' => 'active',
            'deleted_at' => null,
            'removed_at' => null,
        ]);
    }

    private function createReview(): Review
    {
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'Review Provider']);
        $category = ServiceCategory::create(['name' => 'Review '.uniqid(), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Review Service',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
        $booking = Booking::create([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-REVIEW-'.uniqid(),
            'status' => 'completed',
            'payment_status' => 'paid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 0,
            'currency' => 'USD',
        ]);

        return Review::create([
            'booking_id' => $booking->id,
            'reviewer_id' => $client->id,
            'provider_id' => $provider->id,
            'service_id' => $service->id,
            'rating' => 5,
            'comment' => 'Good service.',
            'status' => 'active',
        ]);
    }
}
