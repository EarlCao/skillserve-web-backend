<?php

namespace App\Modules\Commissions\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Commissions\Services\CommissionCalculator;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The commission is inclusive: it comes out of the price the provider
 * advertises, so the customer pays that price and the provider keeps the rest.
 */
class CommissionCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ladder(): void
    {
        CommissionTier::create(['name' => 'Low', 'min_amount' => 0, 'max_amount' => 199.99, 'percentage' => 5, 'is_active' => true]);
        CommissionTier::create(['name' => 'Standard', 'min_amount' => 200, 'max_amount' => 499.99, 'percentage' => 10, 'is_active' => true]);
        CommissionTier::create(['name' => 'Premium', 'min_amount' => 500, 'max_amount' => 999.99, 'percentage' => 15, 'is_active' => true]);
        CommissionTier::create(['name' => 'Top', 'min_amount' => 1000, 'max_amount' => null, 'percentage' => 20, 'is_active' => true]);
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

        return [$profile, $user, $user->createToken('provider-test', ['client:auth'])->plainTextToken];
    }

    private function service(ProviderProfile $profile, float $price): Service
    {
        return Service::create([
            'provider_id' => $profile->id,
            'category_id' => ServiceCategory::create(['name' => 'Aircon '.Str::random(5), 'status' => 'enabled'])->id,
            'title' => 'Aircon Cleaning',
            'description' => 'Split-type aircon deep cleaning.',
            'price' => $price,
            'price_type' => 'fixed',
            'duration' => '2 hours',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
            'is_hidden' => false,
        ]);
    }

    public function test_the_commission_comes_out_of_the_advertised_price(): void
    {
        $this->ladder();

        // The business example: ₱1,000 at 20% leaves the provider ₱800, and
        // the customer still pays ₱1,000.
        $breakdown = app(CommissionCalculator::class)->for(1000);

        $this->assertSame(1000.0, $breakdown['base_amount']);
        $this->assertSame(20.0, $breakdown['rate']);
        $this->assertSame(200.0, $breakdown['commission_amount']);
        $this->assertSame(800.0, $breakdown['net_amount']);
        $this->assertSame('Top', $breakdown['tier_name']);
        $this->assertSame('tier', $breakdown['source']);
    }

    public function test_each_band_charges_its_own_rate(): void
    {
        $this->ladder();
        $calculator = app(CommissionCalculator::class);

        // ₱100 → Low (5%), ₱200 → Standard (10%), ₱750 → Premium (15%),
        // ₱5,000 → Top (20%).
        $this->assertSame(5.0, $calculator->for(100)['rate']);
        $this->assertSame(5.0, $calculator->for(100)['commission_amount']);

        $this->assertSame(10.0, $calculator->for(200)['rate']);
        $this->assertSame(20.0, $calculator->for(200)['commission_amount']);
        $this->assertSame(180.0, $calculator->for(200)['net_amount']);

        $this->assertSame(15.0, $calculator->for(750)['rate']);
        $this->assertSame(20.0, $calculator->for(5000)['rate']);
    }

    public function test_an_amount_no_band_covers_is_charged_nothing(): void
    {
        CommissionTier::create(['name' => 'Standard', 'min_amount' => 200, 'max_amount' => 499.99, 'percentage' => 10, 'is_active' => true]);

        $breakdown = app(CommissionCalculator::class)->for(750);

        $this->assertSame(0.0, $breakdown['commission_amount']);
        $this->assertSame(750.0, $breakdown['net_amount']);
        $this->assertSame('gap', $breakdown['source']);
    }

    public function test_without_tiers_the_legacy_flat_rate_still_applies(): void
    {
        Setting::updateOrCreate(
            ['group' => 'marketplace', 'name' => 'commission_rate'],
            ['payload' => 30],
        );

        $breakdown = app(CommissionCalculator::class)->for(1000);

        $this->assertSame(30.0, $breakdown['rate']);
        $this->assertSame(300.0, $breakdown['commission_amount']);
        $this->assertSame('fallback', $breakdown['source']);
    }

    public function test_disabled_bands_do_not_charge(): void
    {
        CommissionTier::create(['name' => 'Off', 'min_amount' => 0, 'max_amount' => null, 'percentage' => 50, 'is_active' => false]);

        // No active bands at all, so the flat setting (default 10) applies.
        $this->assertSame('fallback', app(CommissionCalculator::class)->for(1000)['source']);
    }

    public function test_a_booking_snapshots_the_rate_it_was_charged(): void
    {
        $this->ladder();
        [$profile] = $this->provider();
        $service = $this->service($profile, 1000);

        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $token = $customer->createToken('client', ['client:auth'])->plainTextToken;

        $this->withToken($token)->postJson('/api/client/v1/bookings', [
            'service_id' => $service->id,
            'scheduled_date' => now()->addWeek()->format('Y-m-d H:i:s'),
        ])->assertStatus(201);

        $booking = Booking::query()->latest('id')->firstOrFail();

        // The customer pays the advertised price; the commission sits inside it.
        $this->assertSame('1000.00', (string) $booking->total_price);
        $this->assertSame('200.00', (string) $booking->platform_fee);
        $this->assertSame('20.00', (string) $booking->commission_rate);
        $this->assertNotNull($booking->commission_tier_id);
    }

    public function test_changing_the_tiers_never_moves_an_existing_booking(): void
    {
        $this->ladder();
        [$profile] = $this->provider();
        $service = $this->service($profile, 1000);

        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $this->withToken($customer->createToken('client', ['client:auth'])->plainTextToken)
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => now()->addWeek()->format('Y-m-d H:i:s'),
            ])->assertStatus(201);

        $booking = Booking::query()->latest('id')->firstOrFail();

        // The administrator doubles the top rate afterwards.
        CommissionTier::query()->where('name', 'Top')->update(['percentage' => 40]);

        $booking->refresh();

        $this->assertSame('20.00', (string) $booking->commission_rate);
        $this->assertSame('200.00', (string) $booking->platform_fee);
    }

    public function test_a_provider_previews_what_a_price_would_earn_them(): void
    {
        $this->ladder();
        [, , $token] = $this->provider();

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/commission-preview?amount=200')
            ->assertOk()
            ->assertJsonPath('data.amount', '200.00')
            ->assertJsonPath('data.commission_rate', '10.00')
            ->assertJsonPath('data.commission_amount', '20.00')
            ->assertJsonPath('data.net_amount', '180.00')
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.source', 'tier');
    }

    public function test_the_preview_requires_an_amount(): void
    {
        [, , $token] = $this->provider();

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/commission-preview')
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_the_preview_is_closed_to_customers(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $this->withToken($customer->createToken('client', ['client:auth'])->plainTextToken)
            ->getJson('/api/client/v1/provider/commission-preview?amount=200')
            ->assertStatus(403);
    }

    public function test_the_provider_service_list_shows_the_earnings_split(): void
    {
        $this->ladder();
        [$profile, , $token] = $this->provider();
        $this->service($profile, 1000);

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/services')
            ->assertOk()
            ->assertJsonPath('data.0.earnings.price', '1000.00')
            ->assertJsonPath('data.0.earnings.commission_rate', '20.00')
            ->assertJsonPath('data.0.earnings.commission_amount', '200.00')
            ->assertJsonPath('data.0.earnings.net_amount', '800.00');
    }

    public function test_the_customer_never_sees_the_commission(): void
    {
        $this->ladder();
        [$profile] = $this->provider();
        $service = $this->service($profile, 1000);

        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $token = $customer->createToken('client', ['client:auth'])->plainTextToken;

        $this->withToken($token)->postJson('/api/client/v1/bookings', [
            'service_id' => $service->id,
            'scheduled_date' => now()->addWeek()->format('Y-m-d H:i:s'),
        ])
            ->assertStatus(201)
            ->assertJsonMissingPath('data.platform_fee')
            ->assertJsonMissingPath('data.commission_rate')
            // What the customer owes is the advertised price, nothing more.
            ->assertJsonPath('data.total_price', '1000.00');

        $this->withToken($token)->getJson('/api/client/v1/services/'.$service->id)
            ->assertOk()
            ->assertJsonMissingPath('data.earnings');
    }

    public function test_the_provider_sees_their_net_on_a_booking(): void
    {
        $this->ladder();
        [$profile, , $providerToken] = $this->provider();
        $service = $this->service($profile, 1000);

        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $this->withToken($customer->createToken('client', ['client:auth'])->plainTextToken)
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => now()->addWeek()->format('Y-m-d H:i:s'),
            ])->assertStatus(201);

        $this->app['auth']->forgetGuards();

        $this->withToken($providerToken)
            ->getJson('/api/client/v1/provider/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.total_price', '1000.00')
            ->assertJsonPath('data.0.platform_fee', '200.00')
            ->assertJsonPath('data.0.commission_rate', '20.00')
            ->assertJsonPath('data.0.net_amount', '800.00');
    }
}
