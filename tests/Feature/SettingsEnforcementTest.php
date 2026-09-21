<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\ClientCommunication\Services\BackgroundNotificationService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * C3: what an administrator switches in System Settings actually changes
 * how the platform behaves.
 */
class SettingsEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function set(string $group, string $name, mixed $value): void
    {
        Setting::query()->updateOrCreate(['group' => $group, 'name' => $name], ['payload' => $value]);
    }

    public function test_bookings_can_be_paused_and_the_commission_sets_the_platform_fee(): void
    {
        [$service] = $this->service(price: 2000);
        $client = $this->customer();
        $token = $this->token($client);
        $body = ['service_id' => $service->id, 'scheduled_date' => now()->addDays(3)->toIso8601String()];

        $this->set('marketplace', 'commission_rate', 15);
        $this->withToken($token)->postJson('/api/client/v1/bookings', $body)->assertCreated();
        $this->assertSame('300.00', (string) Booking::query()->latest('id')->value('platform_fee'));

        $this->set('booking', 'booking_enabled', false);
        $this->withToken($token)->postJson('/api/client/v1/bookings', [...$body, 'scheduled_date' => now()->addDays(4)->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'New bookings are paused right now. Please try again later.');
    }

    public function test_a_late_cancellation_records_the_fee_for_whoever_cancels(): void
    {
        $this->set('booking', 'cancellation_window_hours', 24);
        $this->set('booking', 'client_cancellation_fee_percent', 10);
        $this->set('booking', 'provider_cancellation_fee_percent', 20);
        [$service, $providerUser] = $this->service(price: 1000);
        $client = $this->customer();

        // Early: outside the window, no fee — and the booking says so.
        $early = $this->booking($service, $client, 'confirmed', now()->addDays(3));
        $this->withToken($this->token($client))->getJson("/api/client/v1/bookings/{$early->id}")
            ->assertJsonPath('data.cancellation_policy.is_late', false)
            ->assertJsonPath('data.cancellation_policy.fee_if_cancelled_now', '0.00');
        $this->withToken($this->token($client))->patchJson("/api/client/v1/bookings/{$early->id}/cancel")->assertOk()
            ->assertJsonPath('data.cancellation_fee', null);

        // Late, by the customer: 10%.
        $late = $this->booking($service, $client, 'confirmed', now()->addHours(5));
        $this->withToken($this->token($client))->getJson("/api/client/v1/bookings/{$late->id}")
            ->assertJsonPath('data.cancellation_policy.is_late', true)
            ->assertJsonPath('data.cancellation_policy.fee_if_cancelled_now', '100.00');
        $this->withToken($this->token($client))->patchJson("/api/client/v1/bookings/{$late->id}/cancel")->assertOk()
            ->assertJsonPath('data.cancellation_fee', '100.00');

        // Late, by the provider: 20%. A pending request is never charged.
        $lateForProvider = $this->booking($service, $client, 'confirmed', now()->addHours(2));
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($providerUser))
            ->patchJson("/api/client/v1/provider/bookings/{$lateForProvider->id}/cancel", ['reason' => 'Sudden emergency.'])
            ->assertOk()
            ->assertJsonPath('data.cancellation_fee', '200.00');

        $pending = $this->booking($service, $client, 'pending', now()->addHours(2));
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($client))->patchJson("/api/client/v1/bookings/{$pending->id}/cancel")->assertOk()
            ->assertJsonPath('data.cancellation_fee', null);
    }

    public function test_service_approval_can_be_switched_off(): void
    {
        [, $providerUser, $category] = $this->service();
        $body = ['title' => 'Sofa Cleaning', 'category_id' => $category->id, 'price' => 800, 'price_type' => 'fixed', 'description' => 'Deep clean for sofas.'];

        $this->withToken($this->token($providerUser))->postJson('/api/client/v1/provider/services', $body)
            ->assertCreated()->assertJsonPath('data.approval_status', 'pending');

        $this->set('marketplace', 'service_approval_required', false);
        $this->withToken($this->token($providerUser))->postJson('/api/client/v1/provider/services', [...$body, 'title' => 'Mattress Cleaning'])
            ->assertCreated()->assertJsonPath('data.approval_status', 'approved')->assertJsonPath('data.status', 'published');
    }

    public function test_featured_services_can_be_switched_off(): void
    {
        [$service] = $this->service();
        $service->update(['is_featured' => true]);

        $this->getJson('/api/client/v1/services?featured=1')->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.is_featured', true);

        $this->set('marketplace', 'featured_services_enabled', false);
        $this->getJson('/api/client/v1/services?featured=1')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson("/api/client/v1/services/{$service->id}")->assertJsonPath('data.is_featured', false);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage services', 'feature services'] as $name) {
            Permission::findOrCreate($name);
        }
        $role = Role::create(['name' => 'svc-'.Str::random(4)]);
        $role->syncPermissions(['manage services']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);
        $other = $this->service()[0];
        $this->withToken($admin->createToken('a')->plainTextToken)
            ->patchJson("/api/services/{$other->id}/feature", ['is_featured' => true])
            ->assertStatus(422)->assertJsonValidationErrors('is_featured');
    }

    public function test_provider_sign_ups_can_be_closed(): void
    {
        $this->set('marketplace', 'provider_registration_enabled', false);

        $this->postJson('/api/client/v1/auth/register-provider', [
            'first_name' => 'Juan', 'last_name' => 'Cruz', 'email' => 'juan.new@skillserve.test',
            'password' => 'Secret#2026', 'password_confirmation' => 'Secret#2026',
            'specialization' => 'Plumbing', 'business_name' => 'Juan Plumbing',
        ])->assertForbidden()->assertJsonPath('message', 'Provider sign-ups are closed right now. You can still join as a customer.');
    }

    public function test_maintenance_mode_closes_the_mobile_api_but_not_the_platform_info_or_the_admin(): void
    {
        $this->set('system', 'maintenance_mode', true);

        $this->getJson('/api/client/v1/services')->assertStatus(503)->assertJsonPath('meta.maintenance', true);
        $this->postJson('/api/client/v1/auth/login', ['email' => 'a@b.test', 'password' => 'x'])->assertStatus(503);
        $this->getJson('/api/client/v1/platform')->assertOk()->assertJsonPath('data.maintenance_mode', true);
        $this->getJson('/api/health')->assertOk();
    }

    public function test_the_platform_endpoint_publishes_policies_and_rules(): void
    {
        $this->set('policies', 'privacy_policy', 'We only collect what bookings need.');
        $this->set('booking', 'cancellation_window_hours', 12);

        $this->getJson('/api/client/v1/platform')->assertOk()
            ->assertJsonPath('data.platform_name', 'SkillServe')
            ->assertJsonPath('data.policies.privacy_policy', 'We only collect what bookings need.')
            ->assertJsonPath('data.booking.cancellation_window_hours', 12)
            ->assertJsonPath('data.provider_registration_enabled', true);
    }

    public function test_push_off_keeps_the_feed_but_stops_background_delivery(): void
    {
        $client = $this->customer();
        [$service] = $this->service();
        $booking = $this->booking($service, $client, 'confirmed', now()->addDay());
        $client->notify(new BookingStatusNotification($booking, 'confirmed', 'Booking accepted', 'Accepted.'));
        $background = app(BackgroundNotificationService::class)->issueToken($client)['token'];

        $this->set('notifications', 'push_notifications_enabled', false);

        $this->withToken($background)->getJson('/api/client/v1/notifications/background')->assertOk()->assertJsonCount(0, 'data');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($client))->getJson('/api/client/v1/notifications')->assertOk()->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_the_default_page_size_applies_when_the_caller_sends_none(): void
    {
        foreach (range(1, 4) as $_) {
            $this->service();
        }
        $this->set('system', 'default_page_size', 3);

        $this->getJson('/api/client/v1/services')->assertOk()->assertJsonPath('meta.pagination.per_page', 3);
        $this->getJson('/api/client/v1/services?per_page=10')->assertJsonPath('meta.pagination.per_page', 10);
    }

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'client.'.Str::random(8).'@skillserve.test', 'user_type' => 'customer', 'status' => 'active',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('t', ['client:auth'])->plainTextToken;
    }

    /** @return array{0: Service, 1: User, 2: ServiceCategory} */
    private function service(float $price = 1000): array
    {
        $providerUser = User::factory()->create(['email' => 'p.'.Str::random(8).'@skillserve.test', 'user_type' => 'provider', 'status' => 'active']);
        $profile = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'Provider '.Str::random(4), 'verification_status' => 'verified']);
        $category = ServiceCategory::create(['name' => 'Cat '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $profile->id, 'category_id' => $category->id, 'title' => 'Aircon Cleaning '.Str::random(3),
            'price' => $price, 'price_type' => 'fixed', 'currency' => 'PHP', 'status' => 'published', 'approval_status' => 'approved', 'is_hidden' => false,
        ]);

        return [$service, $providerUser, $category];
    }

    private function booking(Service $service, User $client, string $status, $start): Booking
    {
        return Booking::create([
            'service_id' => $service->id, 'client_id' => $client->id, 'provider_id' => $service->provider_id,
            'booking_number' => 'BK-'.Str::upper(Str::random(10)), 'status' => $status, 'payment_status' => 'unpaid',
            'total_price' => $service->price, 'service_price' => $service->price, 'platform_fee' => 0, 'currency' => 'PHP',
            'scheduled_date' => $start, 'scheduled_end_date' => $start->copy()->addHour(),
        ]);
    }
}
