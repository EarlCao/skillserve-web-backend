<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Shared\Realtime\AdminDataChanged;
use App\Shared\Realtime\MarketplaceChanged;
use App\Shared\Realtime\RealtimeChangeTracker;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RealtimeAdminUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function administratorToken(): string
    {
        Permission::findOrCreate('manage service categories');
        Role::findOrCreate('admin')->givePermissionTo('manage service categories');

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('admin');

        return $user->createToken('test')->plainTextToken;
    }

    public function test_api_write_broadcasts_the_changed_resource_once_after_the_request(): void
    {
        $token = $this->administratorToken();
        Event::fake([AdminDataChanged::class]);

        $this->withToken($token)
            ->postJson('/api/service-categories', ['name' => 'Home Cleaning', 'status' => 'enabled'])
            ->assertCreated();

        Event::assertDispatchedTimes(AdminDataChanged::class, 1);
        Event::assertDispatched(
            AdminDataChanged::class,
            fn (AdminDataChanged $event) => in_array('service-categories', $event->resources, true)
                && count($event->resources) === count(array_unique($event->resources)),
        );
    }

    public function test_untracked_model_changes_do_not_broadcast(): void
    {
        $user = User::factory()->create();
        app(RealtimeChangeTracker::class)->flush();
        Event::fake([AdminDataChanged::class]);

        $user->createToken('device');
        app(RealtimeChangeTracker::class)->flush();

        Event::assertNotDispatched(AdminDataChanged::class);
    }

    public function test_multiple_changes_are_combined_into_one_broadcast(): void
    {
        Event::fake([AdminDataChanged::class]);

        ServiceCategory::create(['name' => 'Plumbing', 'status' => 'enabled']);
        ServiceCategory::create(['name' => 'Electrical', 'status' => 'enabled']);
        User::factory()->create();
        app(RealtimeChangeTracker::class)->flush();

        Event::assertDispatchedTimes(AdminDataChanged::class, 1);
        Event::assertDispatched(
            AdminDataChanged::class,
            fn (AdminDataChanged $event) => $event->resources === ['service-categories', 'users'],
        );
    }

    public function test_catalog_changes_also_tell_the_mobile_marketplace_once(): void
    {
        Event::fake([AdminDataChanged::class, MarketplaceChanged::class]);

        $category = ServiceCategory::create(['name' => 'Electronics', 'status' => 'enabled']);
        $user = User::factory()->create(['user_type' => 'provider']);
        $provider = ProviderProfile::create(['user_id' => $user->id, 'business_name' => 'Fix-It', 'verification_status' => 'verified']);
        Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Laptop repair',
            'price' => 500,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
        app(RealtimeChangeTracker::class)->flush();

        Event::assertDispatchedTimes(MarketplaceChanged::class, 1);
        Event::assertDispatched(
            MarketplaceChanged::class,
            fn (MarketplaceChanged $event) => $event->resources === ['categories', 'providers', 'services'],
        );
    }

    public function test_only_account_changes_customers_can_see_refresh_the_marketplace(): void
    {
        $user = User::factory()->create(['user_type' => 'provider']);
        app(RealtimeChangeTracker::class)->flush();
        Event::fake([MarketplaceChanged::class]);

        // Signing in writes the users table but changes nothing public.
        $user->forceFill(['last_login_at' => now()])->save();
        app(RealtimeChangeTracker::class)->flush();
        Event::assertNotDispatched(MarketplaceChanged::class);

        // Customers never appear in the catalog, whatever changes.
        $customer = User::factory()->create(['user_type' => 'customer']);
        app(RealtimeChangeTracker::class)->flush();
        $customer->update(['name' => 'Renamed Customer', 'status' => 'suspended']);
        app(RealtimeChangeTracker::class)->flush();
        Event::assertNotDispatched(MarketplaceChanged::class);

        // A new name is shown on the provider's cards.
        $user->update(['name' => 'New Display Name']);
        app(RealtimeChangeTracker::class)->flush();
        Event::assertDispatched(
            MarketplaceChanged::class,
            fn (MarketplaceChanged $event) => $event->resources === ['providers'],
        );
    }

    public function test_a_job_starting_or_finishing_refreshes_the_providers_on_job_badge(): void
    {
        $category = ServiceCategory::create(['name' => 'Aircon', 'status' => 'enabled']);
        $providerUser = User::factory()->create(['user_type' => 'provider']);
        $provider = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'Cool', 'verification_status' => 'verified']);
        $service = Service::create([
            'provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'Cleaning', 'price' => 800,
            'price_type' => 'fixed', 'currency' => 'PHP', 'status' => 'published', 'approval_status' => 'approved',
        ]);
        $booking = Booking::create([
            'service_id' => $service->id, 'client_id' => User::factory()->create(['user_type' => 'customer'])->id,
            'provider_id' => $provider->id, 'booking_number' => 'BK-RT-1', 'status' => 'pending', 'payment_status' => 'unpaid',
            'total_price' => 800, 'service_price' => 800, 'platform_fee' => 80, 'currency' => 'PHP', 'scheduled_date' => now()->addDay(),
        ]);
        app(RealtimeChangeTracker::class)->flush();
        Event::fake([MarketplaceChanged::class]);

        // Accepting changes nothing the catalog shows.
        $booking->update(['status' => 'confirmed']);
        app(RealtimeChangeTracker::class)->flush();
        Event::assertNotDispatched(MarketplaceChanged::class);

        foreach (['active', 'completed'] as $status) {
            $booking->update(['status' => $status]);
            app(RealtimeChangeTracker::class)->flush();
        }
        Event::assertDispatchedTimes(MarketplaceChanged::class, 2);
        Event::assertDispatched(MarketplaceChanged::class, fn (MarketplaceChanged $event) => $event->resources === ['providers']);
    }

    public function test_the_marketplace_channel_is_public(): void
    {
        $event = new MarketplaceChanged(['services']);

        $this->assertSame([MarketplaceChanged::CHANNEL], array_map(fn ($channel) => $channel->name, $event->broadcastOn()));
        $this->assertNotInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('marketplace.changed', $event->broadcastAs());
        $this->assertSame(['resources' => ['services']], $event->broadcastWith());
    }

    public function test_unreachable_websocket_server_does_not_fail_the_write(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
        ]);
        Log::spy();

        $tracker = app(RealtimeChangeTracker::class);
        $tracker->record('bookings');
        $tracker->flush();

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_only_role_bearing_accounts_may_join_the_admin_channel(): void
    {
        Role::findOrCreate('admin');
        $administrator = User::factory()->create();
        $administrator->assignRole('admin');
        $client = User::factory()->create(['user_type' => 'customer']);

        $authorize = Broadcast::driver()->getChannels()->get(AdminDataChanged::CHANNEL);

        $this->assertTrue($authorize($administrator));
        $this->assertFalse($authorize($client));
    }

    public function test_api_get_responses_must_be_revalidated(): void
    {
        $response = $this->withToken($this->administratorToken())
            ->getJson('/api/service-categories')
            ->assertOk()
            ->assertHeader('ETag');

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }
}
