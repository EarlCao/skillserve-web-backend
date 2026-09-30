<?php

namespace App\Modules\Reviews\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Actions\RecalculateRatingAggregatesAction;
use App\Modules\Reviews\Models\Review;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RatingAggregatesTest extends TestCase
{
    use RefreshDatabase;

    private ProviderProfile $provider;

    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $this->provider = ProviderProfile::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Rated Provider',
            'verification_status' => 'verified',
        ]);
        $this->category = ServiceCategory::create(['name' => 'Ratings '.Str::random(6), 'status' => 'enabled']);
    }

    public function test_the_provider_rating_is_the_average_of_its_service_ratings(): void
    {
        $services = collect([
            [3, 4],                          // 3.50
            [5],                             // 5.00
            [5, 5, 4, 4, 5],                 // 4.60
            [3, 3, 3, 3, 3, 3, 3, 4, 4, 4],  // 3.30
            [4, 3],                          // 3.50
        ])->map(fn (array $ratings) => $this->serviceWithReviews($ratings));
        $unrated = $this->serviceWithReviews([]);

        $this->assertSame(
            ['3.50', '5.00', '4.60', '3.30', '3.50'],
            $services->map(fn (Service $service) => (string) $service->fresh()->average_rating)->all(),
        );
        $this->assertSame('0.00', (string) $unrated->fresh()->average_rating);

        // (3.5 + 5.0 + 4.6 + 3.3 + 3.5) / 5, not the 3.85 average of all 20
        // reviews, and the unrated service does not drag it towards zero.
        $provider = $this->provider->fresh();
        $this->assertSame('3.98', (string) $provider->average_rating);
        $this->assertSame(20, $provider->total_reviews);
    }

    public function test_hiding_restoring_removing_and_restoring_a_deleted_review_recalculate_the_ratings(): void
    {
        $token = $this->actingAdmin(['edit reviews', 'delete reviews', 'manage deleted records', 'restore deleted records']);
        $fiveStar = $this->serviceWithReviews([5]);
        $this->serviceWithReviews([3, 4]);
        $review = Review::query()->where('service_id', $fiveStar->id)->sole();
        $this->assertRatings($fiveStar, '5.00', '4.25', 3);

        $this->withToken($token)->patchJson("/api/reviews/{$review->id}/hide", ['is_hidden' => true])->assertOk();
        $this->assertRatings($fiveStar, '0.00', '3.50', 2);

        $this->withToken($token)->patchJson("/api/reviews/{$review->id}/hide", ['is_hidden' => false])->assertOk();
        $this->assertRatings($fiveStar, '5.00', '4.25', 3);

        $this->withToken($token)->deleteJson("/api/reviews/{$review->id}")->assertOk();
        $this->assertRatings($fiveStar, '0.00', '3.50', 2);

        $this->withToken($token)->postJson("/api/data-management/deleted/reviews/{$review->id}/restore")->assertOk();
        $this->assertRatings($fiveStar, '5.00', '4.25', 3);
    }

    public function test_a_deleted_service_keeps_counting_towards_the_provider_rating(): void
    {
        $token = $this->actingAdmin(['edit reviews']);
        $poorlyRated = $this->serviceWithReviews([1]);
        $other = $this->serviceWithReviews([5, 5]);
        $poorlyRated->delete();

        $review = Review::query()->where('service_id', $other->id)->firstOrFail();
        $this->withToken($token)->patchJson("/api/reviews/{$review->id}/hide", ['is_hidden' => true])->assertOk();

        $this->assertSame('3.00', (string) $this->provider->fresh()->average_rating);
    }

    public function test_admin_provider_screens_show_the_stored_provider_rating(): void
    {
        $token = $this->actingAdmin(['view providers', 'view provider recognition', 'view top rated providers']);
        Permission::findOrCreate('manage providers');
        $this->serviceWithReviews([5]);
        $this->serviceWithReviews([3, 4]);

        $this->withToken($token)->getJson("/api/providers/{$this->provider->id}")
            ->assertOk()
            ->assertJsonPath('data.average_rating', '4.25');
        $this->withToken($token)->getJson('/api/provider-recognition/providers?sort=average_rating')
            ->assertOk()
            ->assertJsonPath('data.0.average_rating', '4.25');
        $this->withToken($token)->getJson('/api/provider-recognition/top-rated?min_rating=4.2')
            ->assertOk()
            ->assertJsonPath('data.0.average_rating', '4.25');
        $this->withToken($token)->getJson('/api/provider-recognition/top-rated?min_rating=4.3')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_backfill_migration_recomputes_stored_ratings(): void
    {
        $fiveStar = $this->serviceWithReviews([5]);
        $this->serviceWithReviews([3, 4]);
        $fiveStar->forceFill(['average_rating' => 1, 'total_reviews' => 9])->saveQuietly();
        $this->provider->forceFill(['average_rating' => 4, 'total_reviews' => 3])->saveQuietly();

        $migration = require database_path('migrations/2026_09_30_000001_recalculate_ratings_from_service_ratings.php');
        $migration->up();

        $this->assertRatings($fiveStar, '5.00', '4.25', 3);

        $migration->down();

        $this->assertSame('4.00', (string) $this->provider->fresh()->average_rating);
    }

    private function assertRatings(Service $service, string $serviceRating, string $providerRating, int $providerReviews): void
    {
        $provider = $this->provider->fresh();

        $this->assertSame($serviceRating, (string) $service->fresh()->average_rating);
        $this->assertSame($providerRating, (string) $provider->average_rating);
        $this->assertSame($providerReviews, $provider->total_reviews);
    }

    /** @param array<int, int> $ratings */
    private function serviceWithReviews(array $ratings): Service
    {
        $service = Service::create([
            'provider_id' => $this->provider->id,
            'category_id' => $this->category->id,
            'title' => 'Service '.Str::random(6),
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        foreach ($ratings as $rating) {
            $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
            $booking = Booking::create([
                'service_id' => $service->id,
                'client_id' => $client->id,
                'provider_id' => $this->provider->id,
                'booking_number' => 'BK-RATING-'.Str::random(10),
                'status' => 'completed',
                'payment_status' => 'paid',
                'total_price' => 100,
                'service_price' => 100,
                'platform_fee' => 0,
                'currency' => 'PHP',
            ]);
            Review::create([
                'booking_id' => $booking->id,
                'reviewer_id' => $client->id,
                'provider_id' => $this->provider->id,
                'service_id' => $service->id,
                'rating' => $rating,
                'status' => 'active',
            ]);
        }

        app(RecalculateRatingAggregatesAction::class)->handle($service->id, $this->provider->id);

        return $service;
    }

    /** @param array<int, string> $permissions */
    private function actingAdmin(array $permissions): string
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::findOrCreate('rating-admin');
        $role->syncPermissions($permissions);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('test')->plainTextToken;
    }
}
