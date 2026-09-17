<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Shared\Realtime\AdminDataChanged;
use App\Shared\Realtime\RealtimeChangeTracker;
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
