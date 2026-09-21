<?php

namespace App\Modules\Reviews\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** A 8.1 view, A 8.2 search, A 8.3 filter and A 8.4 reported reviews. */
class ReviewListTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviews_are_searched_by_reviewer_provider_service_and_comment(): void
    {
        $token = $this->token();
        $match = $this->review('Maria Santos', 'Juan Aircon Co.', 'Aircon Cleaning', ['comment' => 'Very punctual.']);
        $this->review('Pedro Reyes', 'Pipe Masters', 'Plumbing Repair');

        foreach (['maria', 'juan aircon', 'aircon cleaning', 'punctual'] as $term) {
            $this->withToken($token)->getJson('/api/reviews?search='.urlencode($term))
                ->assertOk()
                ->assertJsonPath('meta.pagination.total', 1)
                ->assertJsonPath('data.0.id', $match->id);
        }
    }

    public function test_reviews_are_filtered_by_rating_status_and_reports(): void
    {
        $token = $this->token();
        $low = $this->review('Ana', 'A Co.', 'Job A', ['rating' => 1, 'is_reported' => true]);
        $hidden = $this->review('Ben', 'B Co.', 'Job B', ['rating' => 5, 'status' => 'hidden']);

        $cases = ['rating=1' => $low, 'is_reported=1' => $low, 'status=hidden' => $hidden];

        foreach ($cases as $query => $expected) {
            $this->withToken($token)->getJson("/api/reviews?{$query}")
                ->assertOk()
                ->assertJsonPath('meta.pagination.total', 1, $query)
                ->assertJsonPath('data.0.id', $expected->id);
        }

        $this->withToken($token)->getJson('/api/reviews?is_reported=0')
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $hidden->id);
    }

    private function token(): string
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage reviews', 'view reviews'] as $name) {
            Permission::findOrCreate($name);
        }
        $role = Role::create(['name' => 'reviews-'.Str::random(6)]);
        $role->syncPermissions(['view reviews']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('test')->plainTextToken;
    }

    private function review(string $reviewer, string $provider, string $service, array $attributes = []): Review
    {
        $client = User::factory()->create(['name' => $reviewer, 'user_type' => 'customer', 'status' => 'active']);
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $profile = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => $provider]);
        $category = ServiceCategory::create(['name' => 'Rev '.Str::random(6), 'status' => 'enabled']);
        $serviceModel = Service::create([
            'provider_id' => $profile->id,
            'category_id' => $category->id,
            'title' => $service,
            'price' => 100,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
        $booking = Booking::create([
            'service_id' => $serviceModel->id,
            'client_id' => $client->id,
            'provider_id' => $profile->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(10)),
            'status' => 'completed',
            'payment_status' => 'paid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'PHP',
        ]);

        return Review::create(array_merge([
            'booking_id' => $booking->id,
            'reviewer_id' => $client->id,
            'provider_id' => $profile->id,
            'service_id' => $serviceModel->id,
            'rating' => 4,
            'comment' => 'Good work.',
            'status' => 'active',
        ], $attributes));
    }
}
