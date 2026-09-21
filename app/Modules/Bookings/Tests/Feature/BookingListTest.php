<?php

namespace App\Modules\Bookings\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** A 7.1 view, A 7.3 search and A 7.4 filter bookings. */
class BookingListTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookings_are_searched_by_number_client_provider_and_service(): void
    {
        $token = $this->token(['view bookings']);
        $aircon = $this->booking('Aircon Cleaning', 'Maria Santos', 'Juan Aircon Co.', ['booking_number' => 'BK-AIRCON-1']);
        $this->booking('Plumbing Repair', 'Pedro Reyes', 'Pipe Masters');

        foreach (['BK-AIRCON', 'maria', 'juan aircon', 'aircon cleaning'] as $term) {
            $this->withToken($token)->getJson('/api/bookings?search='.urlencode($term))
                ->assertOk()
                ->assertJsonPath('meta.pagination.total', 1)
                ->assertJsonPath('data.0.id', $aircon->id);
        }
    }

    public function test_bookings_are_filtered_by_status_payment_provider_client_service_and_date(): void
    {
        $token = $this->token(['view bookings']);
        $old = $this->booking('Old Job', 'Ana', 'Old Co.', ['status' => 'completed', 'payment_status' => 'paid']);
        $old->forceFill(['created_at' => now()->subMonth()])->saveQuietly();
        $new = $this->booking('New Job', 'Ben', 'New Co.', ['status' => 'pending']);

        $cases = [
            'status=completed' => $old,
            'payment_status=paid' => $old,
            "provider_id={$new->provider_id}" => $new,
            "client_id={$new->client_id}" => $new,
            "service_id={$old->service_id}" => $old,
            'date_from='.now()->subDay()->toDateString() => $new,
            'date_to='.now()->subWeeks(2)->toDateString() => $old,
        ];

        foreach ($cases as $query => $expected) {
            $this->withToken($token)->getJson("/api/bookings?{$query}")
                ->assertOk()
                ->assertJsonPath('meta.pagination.total', 1, $query)
                ->assertJsonPath('data.0.id', $expected->id);
        }
    }

    public function test_listing_bookings_needs_a_booking_permission(): void
    {
        $this->withToken($this->token([]))->getJson('/api/bookings')->assertForbidden();
    }

    private function token(array $permissions): string
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage bookings', 'view bookings'] as $name) {
            Permission::findOrCreate($name);
        }
        $role = Role::create(['name' => 'bookings-'.Str::random(6)]);
        $role->syncPermissions($permissions);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('test')->plainTextToken;
    }

    private function booking(string $service, string $client, string $provider, array $attributes = []): Booking
    {
        $clientUser = User::factory()->create(['name' => $client, 'user_type' => 'customer', 'status' => 'active']);
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $profile = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => $provider]);
        $category = ServiceCategory::create(['name' => 'List '.Str::random(6), 'status' => 'enabled']);
        $serviceModel = Service::create([
            'provider_id' => $profile->id,
            'category_id' => $category->id,
            'title' => $service,
            'price' => 100,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        return Booking::create(array_merge([
            'service_id' => $serviceModel->id,
            'client_id' => $clientUser->id,
            'provider_id' => $profile->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(10)),
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'PHP',
        ], $attributes));
    }
}
