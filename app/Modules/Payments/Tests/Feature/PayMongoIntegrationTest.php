<?php

namespace App\Modules\Payments\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Payments\Gateways\PayMongoGateway;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GCash through PayMongo. No request leaves the test process — the HTTP client
 * is faked — so nothing here can touch a real account.
 */
class PayMongoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsk_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.paymongo.secret_key' => 'sk_test_fake',
            'payments.paymongo.webhook_secret' => self::WEBHOOK_SECRET,
            'payments.paymongo.live' => false,
            'payments.gateways.gcash' => 'paymongo',
        ]);

        CommissionTier::create([
            'name' => 'Standard', 'min_amount' => 0, 'max_amount' => null,
            'percentage' => 10, 'is_active' => true,
        ]);
    }

    /** @return array{0: Booking, 1: User, 2: string} */
    private function booking(array $overrides = []): array
    {
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active', 'email' => 'p'.Str::random(6).'@t.test']);
        $profile = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'B', 'verification_status' => 'verified']);
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active', 'email' => 'c'.Str::random(6).'@t.test']);

        $service = Service::create([
            'provider_id' => $profile->id,
            'category_id' => ServiceCategory::create(['name' => 'C'.Str::random(5), 'status' => 'enabled'])->id,
            'title' => 'Aircon Cleaning', 'description' => 'D', 'price' => 200, 'price_type' => 'fixed',
            'duration' => '1 hour', 'currency' => 'PHP', 'status' => 'published',
            'approval_status' => 'approved', 'is_hidden' => false,
        ]);

        $booking = Booking::create(array_merge([
            'service_id' => $service->id, 'client_id' => $client->id, 'provider_id' => $profile->id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)), 'status' => 'confirmed',
            'payment_status' => 'unpaid', 'total_price' => 200, 'service_price' => 200,
            'platform_fee' => 20, 'commission_rate' => 10, 'commission_status' => 'pending',
            'currency' => 'PHP', 'payment_method' => 'gcash', 'confirmed_at' => now(),
        ], $overrides));

        return [$booking, $client, $client->createToken('client', ['client:auth'])->plainTextToken];
    }

    private function fakePayMongo(): void
    {
        Http::fake([
            '*/payment_intents/*/attach' => Http::response(['data' => [
                'id' => 'pi_test_1',
                'attributes' => [
                    'status' => 'awaiting_next_action',
                    'next_action' => ['redirect' => ['url' => 'https://paymongo.test/authorize/abc']],
                ],
            ]]),
            '*/payment_intents' => Http::response(['data' => [
                'id' => 'pi_test_1',
                'attributes' => ['status' => 'awaiting_payment_method', 'client_key' => 'pi_test_1_client_xyz'],
            ]]),
            '*/payment_methods' => Http::response(['data' => ['id' => 'pm_test_1']]),
        ]);
    }

    private function signature(string $payload, ?int $timestamp = null, bool $live = false): string
    {
        $timestamp ??= time();
        $digest = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        return $live
            ? "t={$timestamp},te=wrong,li={$digest}"
            : "t={$timestamp},te={$digest},li=wrong";
    }

    private function paidEvent(string $intentId = 'pi_test_1', string $eventId = 'evt_1'): string
    {
        return json_encode(['data' => [
            'id' => $eventId,
            'attributes' => [
                'type' => 'payment.paid',
                'data' => ['id' => 'pay_test_1', 'attributes' => ['payment_intent_id' => $intentId]],
            ],
        ]]);
    }

    // ---------------------------------------------------------------- paying

    public function test_a_customer_starts_a_gcash_payment_and_gets_a_redirect(): void
    {
        $this->fakePayMongo();
        [$booking, , $token] = $this->booking();

        $this->withToken($token)
            ->postJson("/api/client/v1/bookings/{$booking->id}/pay")
            ->assertOk()
            ->assertJsonPath('data.redirect_url', 'https://paymongo.test/authorize/abc')
            ->assertJsonPath('data.amount', '200.00');

        $intent = PaymentIntent::query()->firstOrFail();
        $this->assertSame('pi_test_1', $intent->external_id);
        $this->assertSame(20000, $intent->amount_minor);

        // Starting a payment must not mark it paid — only the webhook does.
        $this->assertSame('unpaid', $booking->refresh()->payment_status);
    }

    public function test_tapping_pay_twice_resumes_the_same_payment(): void
    {
        $this->fakePayMongo();
        [$booking, , $token] = $this->booking();

        $this->withToken($token)->postJson("/api/client/v1/bookings/{$booking->id}/pay")->assertOk();
        $this->withToken($token)->postJson("/api/client/v1/bookings/{$booking->id}/pay")->assertOk();

        $this->assertSame(1, PaymentIntent::query()->count());
    }

    public function test_an_on_hand_booking_cannot_be_paid_online(): void
    {
        [$booking, , $token] = $this->booking(['payment_method' => 'on_hand']);

        $this->withToken($token)
            ->postJson("/api/client/v1/bookings/{$booking->id}/pay")
            ->assertStatus(422)
            ->assertJsonPath('errors.payment_method.0', 'On-hand payment is paid directly to the provider.');
    }

    public function test_another_customer_cannot_pay_someone_elses_booking(): void
    {
        $this->fakePayMongo();
        [$booking] = $this->booking();

        $stranger = User::factory()->create(['user_type' => 'customer', 'status' => 'active', 'email' => 's'.Str::random(6).'@t.test']);

        $this->withToken($stranger->createToken('client', ['client:auth'])->plainTextToken)
            ->postJson("/api/client/v1/bookings/{$booking->id}/pay")
            ->assertStatus(403);
    }

    public function test_a_paid_booking_cannot_be_paid_again(): void
    {
        [$booking, , $token] = $this->booking(['payment_status' => 'paid']);

        $this->withToken($token)
            ->postJson("/api/client/v1/bookings/{$booking->id}/pay")
            ->assertStatus(409);
    }

    public function test_a_pending_booking_is_not_payable_until_the_provider_accepts(): void
    {
        [$booking, , $token] = $this->booking(['status' => 'pending', 'confirmed_at' => null]);

        $this->withToken($token)
            ->postJson("/api/client/v1/bookings/{$booking->id}/pay")
            ->assertStatus(422);
    }

    // ------------------------------------------------------------- webhooks

    public function test_an_unsigned_or_forged_webhook_is_refused(): void
    {
        $payload = $this->paidEvent();

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [], $payload)
            ->assertStatus(401);

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => 't='.time().',te=forged,li=forged',
        ], $payload)->assertStatus(401);
    }

    public function test_a_signature_for_a_different_body_is_refused(): void
    {
        $signature = $this->signature('{"data":{"id":"evt_other"}}');

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $this->paidEvent())->assertStatus(401);
    }

    public function test_a_stale_signature_is_refused_even_though_it_is_valid(): void
    {
        $payload = $this->paidEvent();
        // Correctly signed, but captured an hour ago: replay protection.
        $signature = $this->signature($payload, time() - 3600);

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $payload)->assertStatus(401);
    }

    public function test_the_live_segment_is_used_when_live_keys_are_configured(): void
    {
        config(['payments.paymongo.live' => true]);

        $payload = $this->paidEvent();

        // Signed into `te` while live mode expects `li`.
        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => $this->signature($payload, null, false),
        ], $payload)->assertStatus(401);

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => $this->signature($payload, null, true),
        ], $payload)->assertOk();
    }

    public function test_without_a_webhook_secret_everything_is_refused(): void
    {
        $payload = $this->paidEvent();
        $signature = $this->signature($payload);

        config(['payments.paymongo.webhook_secret' => '']);

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $payload)->assertStatus(401);
    }

    public function test_a_paid_webhook_marks_the_booking_paid_and_settles_the_commission(): void
    {
        $this->fakePayMongo();
        [$booking, , $token] = $this->booking();
        $this->withToken($token)->postJson("/api/client/v1/bookings/{$booking->id}/pay")->assertOk();

        $payload = $this->paidEvent();

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => $this->signature($payload),
        ], $payload)->assertOk();

        $booking->refresh();

        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame('pay_test_1', $booking->payment_reference);
        // PayMongo collected into SkillServe's account, so the provider never
        // held the platform's share: nothing is outstanding.
        $this->assertSame(CommissionLedger::SETTLED, $booking->commission_status);
    }

    public function test_a_redelivered_webhook_does_not_pay_the_booking_twice(): void
    {
        $this->fakePayMongo();
        [$booking, , $token] = $this->booking();
        $this->withToken($token)->postJson("/api/client/v1/bookings/{$booking->id}/pay")->assertOk();

        $payload = $this->paidEvent();

        foreach (range(1, 3) as $_) {
            $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
                'HTTP_PAYMONGO_SIGNATURE' => $this->signature($payload),
            ], $payload)->assertOk();
        }

        $this->assertSame('paid', $booking->refresh()->payment_status);
        $this->assertSame(1, PaymentIntent::query()->where('status', 'succeeded')->count());
    }

    public function test_a_failed_webhook_records_the_failure_without_touching_the_booking(): void
    {
        $this->fakePayMongo();
        [$booking, , $token] = $this->booking();
        $this->withToken($token)->postJson("/api/client/v1/bookings/{$booking->id}/pay")->assertOk();

        $payload = json_encode(['data' => [
            'id' => 'evt_fail',
            'attributes' => [
                'type' => 'payment.failed',
                'data' => ['id' => 'pay_x', 'attributes' => ['payment_intent_id' => 'pi_test_1', 'last_payment_error' => 'Insufficient funds.']],
            ],
        ]]);

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => $this->signature($payload),
        ], $payload)->assertOk();

        $this->assertSame('unpaid', $booking->refresh()->payment_status);
        $this->assertSame('failed', PaymentIntent::query()->firstOrFail()->status);
    }

    public function test_a_webhook_for_an_unknown_intent_is_acknowledged_not_retried(): void
    {
        $payload = $this->paidEvent('pi_does_not_exist', 'evt_unknown');

        // 200 so PayMongo stops retrying: no retry can make this resolvable.
        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => $this->signature($payload),
        ], $payload)->assertOk();
    }

    public function test_the_client_key_is_never_serialised(): void
    {
        $this->fakePayMongo();
        [$booking, , $token] = $this->booking();

        $response = $this->withToken($token)->postJson("/api/client/v1/bookings/{$booking->id}/pay");

        $this->assertStringNotContainsString('pi_test_1_client_xyz', $response->getContent());
        $this->assertArrayNotHasKey('client_key', PaymentIntent::query()->firstOrFail()->toArray());
    }

    public function test_an_amount_outside_the_gcash_range_is_refused_before_calling_paymongo(): void
    {
        Http::fake();
        [$booking, , $token] = $this->booking(['total_price' => 250000]);

        $this->withToken($token)
            ->postJson("/api/client/v1/bookings/{$booking->id}/pay")
            ->assertStatus(502);

        Http::assertNothingSent();
    }

    public function test_verify_webhook_rejects_malformed_headers(): void
    {
        $gateway = app(PayMongoGateway::class);

        foreach (['', 'garbage', 't=abc,te=x', 'te=x,li=y', 't='.time()] as $header) {
            $this->assertFalse($gateway->verifyWebhook('{}', $header), "accepted: {$header}");
        }
    }
}
