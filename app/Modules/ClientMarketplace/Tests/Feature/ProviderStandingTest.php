<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\ProviderStandingNotification;
use App\Modules\ClientAuthentication\Events\ClientAccountRestricted;
use App\Modules\ClientCommunication\Models\Inquiry;
use App\Modules\ClientMarketplace\Events\ClientBookingChanged;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Customers who booked or messaged a provider learn when an administrator
 * suspends, bans or deletes that provider — and when they are back.
 */
class ProviderStandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Event::fake([ClientBookingChanged::class, ClientAccountRestricted::class]);
    }

    public function test_customers_with_open_bookings_are_told_when_their_provider_is_suspended(): void
    {
        Notification::fake();
        $provider = $this->provider();
        $waiting = $this->customer();
        $finished = $this->customer();
        $open = $this->booking($provider, $waiting, ['status' => 'confirmed']);
        $this->booking($provider, $finished, ['status' => 'completed']);

        $this->withToken($this->adminToken(['view providers', 'suspend providers']))
            ->patchJson("/api/providers/{$provider->id}/suspend", ['reason' => 'Fraud review.'])
            ->assertOk();

        Notification::assertSentTo($waiting, ProviderStandingNotification::class, function (ProviderStandingNotification $notification) use ($waiting, $open) {
            $data = $notification->toArray($waiting);

            return $data['type'] === 'booking_provider_status'
                && $data['action'] === 'suspended'
                && $data['booking_id'] === $open->id
                && $data['title'] === 'Standing Plumbing was suspended'
                && str_contains($data['message'], 'You can cancel it with no cancellation fee');
        });
        Notification::assertNotSentTo($finished, ProviderStandingNotification::class);
        Event::assertDispatched(ClientBookingChanged::class, fn (ClientBookingChanged $event) => $event->userId === $waiting->id && $event->bookingId === $open->id);
    }

    public function test_the_booking_says_why_and_cancelling_it_costs_nothing(): void
    {
        Setting::query()->create(['group' => 'booking', 'name' => 'client_cancellation_fee_percent', 'payload' => 10]);
        $provider = $this->provider();
        $customer = $this->customer();
        // Starts within the 24-hour window, so cancelling would normally be late.
        $booking = $this->booking($provider, $customer, ['status' => 'confirmed', 'scheduled_date' => now()->addHours(5), 'scheduled_end_date' => now()->addHours(6)]);
        $provider->user->update(['status' => 'suspended', 'suspended_at' => now()]);
        $token = $this->clientToken($customer);

        $this->withToken($token)->getJson("/api/client/v1/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.provider.account_status', 'suspended')
            ->assertJsonPath('data.provider.account_notice', 'This provider has been suspended by SkillServe, so they cannot work on this booking. You can cancel it with no cancellation fee. If you already paid them, contact support and we will help you get it back.')
            ->assertJsonPath('data.cancellation_policy.is_late', false)
            ->assertJsonPath('data.cancellation_policy.fee_if_cancelled_now', '0.00');

        $this->withToken($token)->getJson('/api/client/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.provider.account_status', 'suspended');

        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/cancel", ['reason' => 'My provider was suspended.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_fee', null);
    }

    public function test_an_active_provider_has_no_notice_and_a_deleted_one_says_so(): void
    {
        $provider = $this->provider();
        $customer = $this->customer();
        $booking = $this->booking($provider, $customer, ['status' => 'completed']);
        $token = $this->clientToken($customer);

        $this->withToken($token)->getJson("/api/client/v1/bookings/{$booking->id}")
            ->assertJsonPath('data.provider.account_status', 'active')
            ->assertJsonPath('data.provider.account_notice', null);

        $provider->user->delete();

        $this->withToken($token)->getJson("/api/client/v1/bookings/{$booking->id}")
            ->assertJsonPath('data.provider.account_status', 'deleted')
            ->assertJsonPath('data.provider.account_notice', "This provider's account was deleted.");
    }

    public function test_customers_hear_when_the_provider_is_back(): void
    {
        Notification::fake();
        $provider = $this->provider();
        $customer = $this->customer();
        $this->booking($provider, $customer, ['status' => 'pending']);
        $token = $this->adminToken(['view providers', 'suspend providers', 'activate providers']);

        $this->withToken($token)->patchJson("/api/providers/{$provider->id}/suspend", ['reason' => 'Fraud review.'])->assertOk();
        $this->withToken($token)->patchJson("/api/providers/{$provider->id}/activate")->assertOk();

        Notification::assertSentTo($customer, ProviderStandingNotification::class, fn ($n) => $n->toArray($customer)['action'] === 'active'
            && $n->toArray($customer)['title'] === 'Standing Plumbing is available again');
        Notification::assertSentToTimes($customer, ProviderStandingNotification::class, 2);
    }

    public function test_the_chat_list_shows_a_suspended_provider(): void
    {
        $provider = $this->provider();
        $customer = $this->customer();
        $inquiry = Inquiry::create(['customer_id' => $customer->id, 'provider_profile_id' => $provider->id]);
        Message::query()->create(['inquiry_id' => $inquiry->id, 'sender_id' => $customer->id, 'receiver_id' => $provider->user_id, 'content' => 'Are you free Friday?', 'status' => 'active']);
        $provider->user->update(['status' => 'banned', 'banned_at' => now()]);

        $this->withToken($this->clientToken($customer))->getJson('/api/client/v1/conversations?include_inquiries=1')
            ->assertOk()
            ->assertJsonPath('data.0.counterpart.account_status', 'banned')
            ->assertJsonPath('data.0.counterpart.account_notice', 'This provider has been banned from SkillServe.');

        // The provider's side of the list carries nothing of the sort.
        Auth::forgetGuards();
        $provider->user->update(['status' => 'active', 'banned_at' => null]);
        $this->withToken($this->clientToken($provider->user))->getJson('/api/client/v1/conversations?include_inquiries=1')
            ->assertOk()
            ->assertJsonMissingPath('data.0.counterpart.account_status');
    }

    public function test_a_provider_suspended_from_a_report_shows_as_suspended_and_can_be_activated(): void
    {
        foreach (['view reports', 'manage reports', 'manage moderation', 'manage users', 'suspend users', 'manage providers', 'view providers', 'activate providers'] as $name) {
            Permission::findOrCreate($name);
        }
        $role = Role::create(['name' => 'moderator-'.Str::random(5)]);
        $role->syncPermissions(['view reports', 'manage moderation', 'suspend users', 'view providers', 'activate providers']);
        $moderator = User::factory()->create(['status' => 'active']);
        $moderator->assignRole($role);
        $token = $moderator->createToken('m')->plainTextToken;
        $provider = $this->provider();
        $report = Report::create([
            'reportable_type' => $provider->user->getMorphClass(),
            'reportable_id' => $provider->user_id,
            'reporter_id' => $this->customer()->id,
            'reason' => 'fraud',
            'description' => 'Took my downpayment and vanished.',
            'status' => 'pending',
        ]);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", ['action' => 'suspend', 'reason' => 'Took a downpayment and vanished.'])
            ->assertOk();

        $this->assertNotNull($provider->fresh()->suspended_at);
        $this->assertSame('suspended', $provider->user->fresh()->status);
        $this->withToken($token)->getJson("/api/providers/{$provider->id}")
            ->assertJsonPath('data.is_suspended', true)
            ->assertJsonPath('data.account_status', 'suspended');

        $this->withToken($token)->patchJson("/api/providers/{$provider->id}/activate")->assertOk();
        $this->assertSame('active', $provider->user->fresh()->status);
    }

    public function test_a_banned_provider_can_be_unbanned_from_provider_management(): void
    {
        Notification::fake();
        $provider = $this->provider();
        $customer = $this->customer();
        $this->booking($provider, $customer, ['status' => 'confirmed']);
        $provider->user->update(['status' => 'banned', 'banned_at' => now(), 'ban_reason' => 'Scam.']);

        $viewer = $this->adminToken(['view providers']);
        $token = $this->adminToken(['view providers', 'activate providers']);

        $this->withToken($viewer)->patchJson("/api/providers/{$provider->id}/unban")->assertForbidden();
        Auth::forgetGuards();

        $this->withToken($token)->getJson("/api/providers/{$provider->id}")->assertJsonPath('data.account_status', 'banned');
        $this->withToken($token)
            ->patchJson("/api/providers/{$provider->id}/unban", ['reason' => 'Appeal accepted.'])
            ->assertOk()
            ->assertJsonPath('message', 'Ban lifted.')
            ->assertJsonPath('data.account_status', 'active');

        $this->assertSame('Appeal accepted.', $provider->user->fresh()->unban_reason);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $provider->user_id, 'description' => 'user_unbanned']);
        Notification::assertSentTo($customer, ProviderStandingNotification::class, fn ($n) => $n->toArray($customer)['action'] === 'active');

        $this->withToken($token)->patchJson("/api/providers/{$provider->id}/unban")->assertUnprocessable();
    }

    public function test_the_backfill_suspends_the_accounts_of_providers_suspended_before(): void
    {
        $legacy = $this->provider();
        $legacy->update(['suspended_at' => now()->subWeek(), 'suspension_reason' => 'Old suspension.']);
        $legacy->user->createToken('app', ['client:auth']);
        $active = $this->provider();

        $migration = require database_path('migrations/2026_10_15_000001_suspend_accounts_of_suspended_providers.php');
        $migration->up();

        $user = $legacy->user->fresh();
        $this->assertSame('suspended', $user->status);
        $this->assertSame('Old suspension.', $user->suspension_reason);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame('active', $active->user->fresh()->status);

        $migration->down();
        $this->assertSame('active', $legacy->user->fresh()->status);
    }

    /** @param  array<int, string>  $permissions */
    private function adminToken(array $permissions): string
    {
        foreach (['manage providers', 'view providers', 'suspend providers', 'activate providers'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::create(['name' => 'providers-'.Str::random(6)]);
        $role->syncPermissions($permissions);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('test')->plainTextToken;
    }

    private function clientToken(User $user): string
    {
        return $user->createToken('client-test', ['client:auth'])->plainTextToken;
    }

    private function customer(): User
    {
        return User::factory()->create(['user_type' => 'customer', 'status' => 'active', 'email_verified_at' => now()]);
    }

    private function provider(): ProviderProfile
    {
        $user = User::factory()->create(['user_type' => 'provider', 'status' => 'active', 'email_verified_at' => now()]);

        return ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Standing Plumbing',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    private function booking(ProviderProfile $provider, User $customer, array $attributes): Booking
    {
        $category = ServiceCategory::create(['name' => 'Plumbing '.Str::random(5), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Pipe Repair',
            'price' => 1500,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        return Booking::create(array_merge([
            'service_id' => $service->id,
            'client_id' => $customer->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-STD-'.Str::random(10),
            'payment_status' => 'unpaid',
            'total_price' => 1500,
            'service_price' => 1500,
            'platform_fee' => 150,
            'currency' => 'PHP',
            'scheduled_date' => now()->addDays(3),
            'scheduled_end_date' => now()->addDays(3)->addHours(2),
        ], $attributes));
    }
}
