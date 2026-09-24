<?php

namespace App\Modules\IdentityVerification\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Enforcement of the National ID requirement.
 *
 * Unverified accounts keep full read access — register, sign in, browse,
 * search, view services — and are stopped only at protected transactional
 * actions. The rule ships switched off, and grandfathers accounts created
 * before the configured cutover.
 */
class IdentityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        CommissionTier::create([
            'name' => 'Standard', 'min_amount' => 0, 'max_amount' => null,
            'percentage' => 10, 'is_active' => true,
        ]);
    }

    private function require(?string $from = null): void
    {
        Setting::updateOrCreate(
            ['group' => 'identity', 'name' => 'identity_verification_required'],
            ['payload' => true],
        );

        Setting::updateOrCreate(
            ['group' => 'identity', 'name' => 'identity_verification_enforced_from'],
            ['payload' => $from ?? ''],
        );
    }

    /** @return array{0: User, 1: string} */
    private function customer(?string $createdAt = null): array
    {
        $user = User::factory()->create([
            'email' => 'customer.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        if ($createdAt !== null) {
            $user->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return [$user, $user->createToken('client', ['client:auth'])->plainTextToken];
    }

    /** @return array{0: ProviderProfile, 1: User, 2: string} */
    private function provider(): array
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(8).'@skillserve.test',
            'user_type' => 'provider',
            'status' => 'active',
        ]);
        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Juan Aircon Services',
            'verification_status' => 'verified',
            'is_accepting_bookings' => true,
        ]);

        return [$profile, $user, $user->createToken('provider', ['client:auth'])->plainTextToken];
    }

    private function service(ProviderProfile $profile): Service
    {
        return Service::create([
            'provider_id' => $profile->id,
            'category_id' => ServiceCategory::create(['name' => 'Aircon '.Str::random(5), 'status' => 'enabled'])->id,
            'title' => 'Aircon Cleaning',
            'description' => 'Split-type aircon deep cleaning.',
            'price' => 200,
            'price_type' => 'fixed',
            'duration' => '2 hours',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
            'is_hidden' => false,
        ]);
    }

    private function verify(User $user): void
    {
        IdentityVerification::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['status' => IdentityVerification::VERIFIED, 'id_number_hash' => hash('sha256', (string) $user->id)],
        );
    }

    private function book(string $token, Service $service): TestResponse
    {
        return $this->withToken($token)->postJson('/api/client/v1/bookings', [
            'service_id' => $service->id,
            'scheduled_date' => now()->addWeek()->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_nothing_changes_while_the_requirement_is_switched_off(): void
    {
        [$profile] = $this->provider();
        $service = $this->service($profile);
        [, $token] = $this->customer();

        // Default configuration: unverified accounts transact exactly as before.
        $this->book($token, $service)->assertStatus(201);
    }

    public function test_an_unverified_customer_can_still_browse_and_search(): void
    {
        $this->require();
        [$profile] = $this->provider();
        $this->service($profile);
        [, $token] = $this->customer();

        $this->withToken($token)->getJson('/api/client/v1/services')->assertOk();
        $this->withToken($token)->getJson('/api/client/v1/services?search=aircon')->assertOk();
        $this->withToken($token)->getJson('/api/client/v1/categories')->assertOk();
        $this->withToken($token)->getJson('/api/client/v1/providers')->assertOk();
    }

    public function test_an_unverified_customer_cannot_book(): void
    {
        $this->require();
        [$profile] = $this->provider();
        $service = $this->service($profile);
        [, $token] = $this->customer();

        $this->book($token, $service)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Verify your National ID before you can do this.');
    }

    public function test_a_verified_customer_can_book(): void
    {
        $this->require();
        [$profile] = $this->provider();
        $service = $this->service($profile);
        [$user, $token] = $this->customer();

        $this->verify($user);

        $this->book($token, $service)->assertStatus(201);
    }

    public function test_a_pending_submission_does_not_yet_unlock_transacting(): void
    {
        $this->require();
        [$profile] = $this->provider();
        $service = $this->service($profile);
        [$user, $token] = $this->customer();

        IdentityVerification::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['status' => IdentityVerification::PENDING],
        );

        $this->book($token, $service)
            ->assertStatus(403)
            ->assertJsonPath('errors.identity.0', 'Your National ID is still being reviewed.');
    }

    public function test_accounts_created_before_the_cutover_are_grandfathered(): void
    {
        $this->require(now()->subDays(5)->toDateString());

        [$profile] = $this->provider();
        $service = $this->service($profile);

        // Registered before the cutover: keeps transacting unverified.
        [, $oldToken] = $this->customer(now()->subDays(30)->toDateTimeString());
        $this->book($oldToken, $service)->assertStatus(201);

        $this->app['auth']->forgetGuards();

        // Registered after it: must verify.
        [, $newToken] = $this->customer(now()->subDay()->toDateTimeString());
        $this->book($newToken, $service)->assertStatus(403);
    }

    public function test_an_unverified_provider_cannot_accept_work_or_publish_services(): void
    {
        $this->require();
        [$profile, , $providerToken] = $this->provider();
        $service = $this->service($profile);

        [$customer, $customerToken] = $this->customer();
        $this->verify($customer);
        $this->book($customerToken, $service)->assertStatus(201);

        $booking = Booking::query()->latest('id')->firstOrFail();

        $this->app['auth']->forgetGuards();

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/confirm")
            ->assertStatus(403);

        $this->withToken($providerToken)
            ->postJson('/api/client/v1/provider/services', [
                'title' => 'New Service',
                'description' => 'Something new.',
                'category_id' => ServiceCategory::create(['name' => 'X'.Str::random(5), 'status' => 'enabled'])->id,
                'price' => 500,
                'price_type' => 'fixed',
                'duration' => '1 hour',
            ])
            ->assertStatus(403);
    }

    public function test_a_verified_provider_can_accept_work(): void
    {
        $this->require();
        [$profile, $providerUser, $providerToken] = $this->provider();
        $service = $this->service($profile);
        $this->verify($providerUser);

        [$customer, $customerToken] = $this->customer();
        $this->verify($customer);
        $this->book($customerToken, $service)->assertStatus(201);

        $booking = Booking::query()->latest('id')->firstOrFail();

        $this->app['auth']->forgetGuards();

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/confirm")
            ->assertOk();
    }

    public function test_an_unverified_provider_can_still_manage_their_profile(): void
    {
        $this->require();
        [, , $providerToken] = $this->provider();

        $this->withToken($providerToken)->getJson('/api/client/v1/provider/profile')->assertOk();

        $this->withToken($providerToken)
            ->patchJson('/api/client/v1/provider/profile', ['bio' => 'Ten years of aircon work.'])
            ->assertOk();
    }

    public function test_the_eligibility_endpoint_explains_the_block(): void
    {
        $this->require();
        [$user, $token] = $this->customer();

        $this->withToken($token)
            ->getJson('/api/client/v1/transaction-eligibility')
            ->assertOk()
            ->assertJsonPath('data.account_type', 'customer')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason', 'identity_unverified')
            ->assertJsonPath('data.identity_required', true);

        $this->verify($user);

        $this->withToken($token)
            ->getJson('/api/client/v1/transaction-eligibility')
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.reason', null);
    }

    public function test_eligibility_reports_identity_before_an_unpaid_commission(): void
    {
        $this->require();
        [$profile, $providerUser, $providerToken] = $this->provider();
        [$customer] = $this->customer();

        Booking::create([
            'service_id' => $this->service($profile)->id,
            'client_id' => $customer->id,
            'provider_id' => $profile->id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)),
            'status' => 'completed',
            'payment_status' => 'paid',
            'total_price' => 200, 'service_price' => 200, 'platform_fee' => 20,
            'commission_rate' => 10, 'commission_status' => 'outstanding',
            'currency' => 'PHP',
        ]);

        // Both blocks apply; the app should be sent to the ID screen, which is
        // the one the provider can actually act on.
        $this->withToken($providerToken)
            ->getJson('/api/client/v1/transaction-eligibility')
            ->assertOk()
            ->assertJsonPath('data.reason', 'identity_unverified')
            ->assertJsonPath('data.outstanding_total', '20.00');
    }

    public function test_an_unverified_account_is_never_told_it_is_eligible(): void
    {
        $this->require();
        [, $token] = $this->customer();

        // The advisory endpoint and the enforced action must agree.
        $advisory = $this->withToken($token)->getJson('/api/client/v1/transaction-eligibility');

        $this->assertFalse($advisory->json('data.eligible'));
    }
}
