<?php

namespace App\Modules\Payments\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Enums\PaymentMethod;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Exceptions\GatewayNotImplemented;
use App\Modules\Payments\Gateways\ManualGateway;
use App\Modules\Payments\Gateways\PayMongoGateway;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * SkillServe supports exactly two payment methods, and both settle by hand
 * until PayMongo is actually integrated.
 */
class PaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_two_payment_methods_are_canonical(): void
    {
        $this->assertSame(['on_hand', 'gcash'], PaymentMethod::canonical());
    }

    public function test_the_deprecated_cash_alias_maps_to_on_hand(): void
    {
        // The mobile build in the field defaults to "cash"; rejecting it would
        // fail every booking until that app ships an update.
        $this->assertSame(PaymentMethod::OnHand, PaymentMethod::fromInput('cash'));
        $this->assertSame(PaymentMethod::OnHand, PaymentMethod::fromInput('on_hand'));
        $this->assertSame(PaymentMethod::GCash, PaymentMethod::fromInput('gcash'));
    }

    public function test_removed_methods_no_longer_resolve(): void
    {
        foreach (['credit_card', 'debit_card', 'bank_transfer', 'paypal'] as $removed) {
            $this->assertNull(PaymentMethod::fromInput($removed));
        }

        $this->assertNull(PaymentMethod::fromInput(null));
        $this->assertNull(PaymentMethod::fromInput(''));
    }

    public function test_both_methods_resolve_to_the_manual_gateway_today(): void
    {
        $manager = app(PaymentGatewayManager::class);

        $this->assertInstanceOf(ManualGateway::class, $manager->for(PaymentMethod::OnHand));
        $this->assertInstanceOf(ManualGateway::class, $manager->for(PaymentMethod::GCash));
        $this->assertFalse($manager->anyGatewayCollects());
    }

    public function test_an_unknown_gateway_is_refused_rather_than_defaulted(): void
    {
        config(['payments.gateways.gcash' => 'stripe']);

        // Falling back silently would change who holds the money.
        $this->expectException(InvalidArgumentException::class);

        app(PaymentGatewayManager::class)->for(PaymentMethod::GCash);
    }

    public function test_the_manual_gateway_refuses_to_pretend_it_collected(): void
    {
        $gateway = new ManualGateway;

        $this->assertFalse($gateway->collectsPayment());
        $this->assertFalse($gateway->verifyWebhook('{}', 'sig'));

        $this->expectException(RuntimeException::class);
        $gateway->collect(new Booking, 'key-1');
    }

    public function test_the_paymongo_gateway_is_scaffolding_and_says_so(): void
    {
        $gateway = new PayMongoGateway;

        $this->assertInstanceOf(PaymentGateway::class, $gateway);
        $this->assertSame('paymongo', $gateway->name());

        // Never silently succeed: a booking marked paid with no money moved is
        // the worst failure this subsystem has.
        $this->expectException(GatewayNotImplemented::class);
        $gateway->collect(new Booking, 'key-1');
    }

    public function test_an_unverified_webhook_is_refused_not_trusted(): void
    {
        $this->assertFalse((new PayMongoGateway)->verifyWebhook('{"paid":true}', 'forged'));
    }

    public function test_no_paymongo_credentials_are_committed(): void
    {
        $this->assertNull(config('payments.paymongo.secret_key'));
        $this->assertNull(config('payments.paymongo.webhook_secret'));
    }

    public function test_a_collecting_gateway_settles_the_commission_on_payment(): void
    {
        CommissionTier::create([
            'name' => 'Standard', 'min_amount' => 0, 'max_amount' => null,
            'percentage' => 10, 'is_active' => true,
        ]);

        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active', 'email' => 'p'.Str::random(6).'@t.test']);
        $profile = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'B', 'verification_status' => 'verified']);
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active', 'email' => 'c'.Str::random(6).'@t.test']);

        $service = Service::create([
            'provider_id' => $profile->id,
            'category_id' => ServiceCategory::create(['name' => 'C'.Str::random(5), 'status' => 'enabled'])->id,
            'title' => 'T', 'description' => 'D', 'price' => 200, 'price_type' => 'fixed',
            'duration' => '1 hour', 'currency' => 'PHP', 'status' => 'published',
            'approval_status' => 'approved', 'is_hidden' => false,
        ]);

        $booking = Booking::create([
            'service_id' => $service->id, 'client_id' => $client->id, 'provider_id' => $profile->id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)), 'status' => 'completed',
            'payment_status' => 'unpaid', 'total_price' => 200, 'service_price' => 200,
            'platform_fee' => 20, 'commission_rate' => 10, 'commission_status' => 'pending',
            'currency' => 'PHP', 'payment_method' => 'gcash',
        ]);

        $ledger = app(CommissionLedger::class);

        // Manual today: the provider collects, so they owe the share back.
        $ledger->markOutstanding($booking);
        $this->assertSame(CommissionLedger::OUTSTANDING, $booking->refresh()->commission_status);

        // Point GCash at a gateway that collects, as PayMongo eventually will.
        $booking->update(['commission_status' => CommissionLedger::PENDING]);
        config(['payments.gateways.gcash' => 'paymongo']);

        app(CommissionLedger::class)->markOutstanding($booking);

        // SkillServe already holds its share, so nothing is outstanding.
        $this->assertSame(CommissionLedger::SETTLED, $booking->refresh()->commission_status);
    }
}
