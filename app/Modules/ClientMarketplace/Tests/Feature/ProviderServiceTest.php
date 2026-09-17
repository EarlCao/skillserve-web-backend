<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

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

class ProviderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array{0: ProviderProfile, 1: User, 2: string}
     */
    private function provider(string $verificationStatus = 'verified'): array
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(8).'@skillserve.test',
            'user_type' => 'provider',
            'status' => 'active',
        ]);
        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Juan Aircon Services',
            'verification_status' => $verificationStatus,
        ]);

        return [$profile, $user, $user->createToken('provider-test', ['client:auth'])->plainTextToken];
    }

    private function category(): ServiceCategory
    {
        return ServiceCategory::create(['name' => 'Aircon '.Str::random(5), 'status' => 'enabled']);
    }

    private function payload(ServiceCategory $category, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Aircon Cleaning',
            'description' => 'Split-type aircon deep cleaning.',
            'category_id' => $category->id,
            'price' => 1500,
            'price_type' => 'fixed',
            'duration' => '2 hours',
            'location' => 'Quezon City',
        ], $overrides);
    }

    public function test_provider_reads_their_own_profile_in_any_verification_state(): void
    {
        [$profile, , $token] = $this->provider('pending');

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.business_name', 'Juan Aircon Services')
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.is_suspended', false);

        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->withToken($customer->createToken('client', ['client:auth'])->plainTextToken)
            ->getJson('/api/client/v1/provider/profile')
            ->assertForbidden();
    }

    public function test_verified_provider_submits_a_service_for_approval(): void
    {
        [$profile, $user, $token] = $this->provider();

        $response = $this->withToken($token)
            ->postJson('/api/client/v1/provider/services', $this->payload($this->category()))
            ->assertCreated()
            ->assertJsonPath('data.approval_status', 'pending')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.price', '1500.00');

        $this->assertDatabaseHas('services', [
            'id' => $response->json('data.id'),
            'provider_id' => $profile->id,
            'created_by' => $user->id,
        ]);
    }

    public function test_unverified_provider_cannot_submit_services(): void
    {
        [, , $token] = $this->provider('pending');

        $this->withToken($token)
            ->postJson('/api/client/v1/provider/services', $this->payload($this->category()))
            ->assertForbidden();
    }

    public function test_create_requires_price_and_an_enabled_category(): void
    {
        [, , $token] = $this->provider();
        $disabled = ServiceCategory::create(['name' => 'Retired', 'status' => 'disabled']);

        $this->withToken($token)
            ->postJson('/api/client/v1/provider/services', ['title' => 'No price', 'category_id' => $disabled->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category_id', 'price', 'price_type']]);
    }

    public function test_customer_and_admin_tokens_cannot_use_provider_endpoints(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $this->withToken($customer->createToken('client', ['client:auth'])->plainTextToken)
            ->getJson('/api/client/v1/provider/services')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->getJson('/api/client/v1/provider/services')
            ->assertForbidden();
    }

    public function test_provider_lists_only_their_own_services(): void
    {
        [$profile, , $token] = $this->provider();
        [$otherProfile] = $this->provider();
        $category = $this->category();
        Service::create(['provider_id' => $profile->id, 'category_id' => $category->id, 'title' => 'Mine', 'approval_status' => 'rejected', 'status' => 'draft', 'rejection_reason' => 'Add photos']);
        $other = Service::create(['provider_id' => $otherProfile->id, 'category_id' => $category->id, 'title' => 'Theirs', 'approval_status' => 'approved', 'status' => 'published']);

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/services')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine')
            ->assertJsonPath('data.0.rejection_reason', 'Add photos');

        $this->withToken($token)->getJson("/api/client/v1/provider/services/{$other->id}")->assertNotFound();
        $this->withToken($token)->putJson("/api/client/v1/provider/services/{$other->id}", ['title' => 'Hijacked'])->assertNotFound();
        $this->withToken($token)->deleteJson("/api/client/v1/provider/services/{$other->id}")->assertNotFound();
    }

    public function test_provider_edit_returns_the_service_to_pending_without_notifying_themselves(): void
    {
        [$profile, $user, $token] = $this->provider();
        $service = Service::create([
            'provider_id' => $profile->id, 'category_id' => $this->category()->id, 'title' => 'Aircon Cleaning',
            'price' => 1500, 'price_type' => 'fixed', 'currency' => 'PHP',
            'status' => 'published', 'approval_status' => 'approved', 'approved_at' => now(),
        ]);

        $this->withToken($token)
            ->putJson("/api/client/v1/provider/services/{$service->id}", ['price' => 1800])
            ->assertOk()
            ->assertJsonPath('data.price', '1800.00')
            ->assertJsonPath('data.approval_status', 'pending')
            ->assertJsonPath('data.status', 'draft');

        $this->assertCount(0, $user->notifications()->get());
    }

    public function test_saving_unchanged_values_keeps_an_approved_service_live(): void
    {
        [$profile, , $token] = $this->provider();
        $service = Service::create([
            'provider_id' => $profile->id, 'category_id' => $this->category()->id, 'title' => 'Aircon Cleaning',
            'price' => 1500, 'price_type' => 'fixed', 'status' => 'published', 'approval_status' => 'approved',
        ]);

        $this->withToken($token)
            ->putJson("/api/client/v1/provider/services/{$service->id}", ['title' => 'Aircon Cleaning', 'price' => '1500.00'])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved')
            ->assertJsonPath('data.status', 'published');
    }

    public function test_service_with_open_bookings_cannot_be_deleted(): void
    {
        [$profile, , $token] = $this->provider();
        $service = Service::create([
            'provider_id' => $profile->id, 'category_id' => $this->category()->id, 'title' => 'Booked',
            'price' => 1500, 'currency' => 'PHP', 'status' => 'published', 'approval_status' => 'approved',
        ]);
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $booking = Booking::create([
            'service_id' => $service->id, 'client_id' => $client->id, 'provider_id' => $profile->id,
            'booking_number' => 'BK-TEST-'.Str::random(12), 'status' => 'confirmed', 'payment_status' => 'unpaid',
            'total_price' => 1500, 'service_price' => 1500, 'platform_fee' => 0, 'currency' => 'PHP',
            'scheduled_date' => now()->addDay(), 'scheduled_end_date' => now()->addDay()->addHour(),
        ]);

        $this->withToken($token)->deleteJson("/api/client/v1/provider/services/{$service->id}")->assertStatus(409);

        $booking->update(['status' => 'completed']);

        $this->withToken($token)->deleteJson("/api/client/v1/provider/services/{$service->id}")->assertOk();
        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    public function test_provider_reads_moderation_notifications(): void
    {
        [$profile, $user, $token] = $this->provider();
        $service = Service::create([
            'provider_id' => $profile->id, 'category_id' => $this->category()->id, 'title' => 'Aircon Cleaning',
            'status' => 'draft', 'approval_status' => 'pending',
        ]);

        Permission::findOrCreate('manage services');
        Role::findOrCreate('admin')->givePermissionTo('manage services');
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->patchJson("/api/services/{$service->id}/approve")
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/client/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.data.type', 'service_moderation')
            ->assertJsonPath('data.0.data.action', 'approved')
            ->assertJsonPath('data.0.data.service_id', $service->id);

        $this->assertSame(1, $user->unreadNotifications()->count());
    }
}
