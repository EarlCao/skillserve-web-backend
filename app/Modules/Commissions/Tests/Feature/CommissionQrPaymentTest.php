<?php

namespace App\Modules\Commissions\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionRemittance;
use App\Modules\Commissions\Models\CommissionSettlement;
use App\Modules\Commissions\Notifications\CommissionRemittanceNotification;
use App\Modules\Commissions\Notifications\CommissionSettlementNotification;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A provider pays their commission with a PayMongo QR Ph code. The amount is
 * the server's, and only PayMongo's signed webhook — with a matching amount —
 * settles anything.
 */
class CommissionQrPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsk_test_secret';

    private const QR = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config([
            'payments.paymongo.secret_key' => 'sk_test_fake',
            'payments.paymongo.webhook_secret' => self::WEBHOOK_SECRET,
            'payments.paymongo.live' => false,
        ]);
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

    private function owed(ProviderProfile $profile, float $fee = 20): Booking
    {
        $service = Service::create([
            'provider_id' => $profile->id,
            'category_id' => ServiceCategory::create(['name' => 'Aircon '.Str::random(5), 'status' => 'enabled'])->id,
            'title' => 'Aircon Cleaning', 'description' => 'D', 'price' => $fee * 10, 'price_type' => 'fixed',
            'duration' => '2 hours', 'currency' => 'PHP', 'status' => 'published',
            'approval_status' => 'approved', 'is_hidden' => false,
        ]);
        $client = User::factory()->create(['email' => 'c.'.Str::random(8).'@skillserve.test', 'user_type' => 'customer', 'status' => 'active']);

        return Booking::create([
            'service_id' => $service->id, 'client_id' => $client->id, 'provider_id' => $profile->id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)), 'status' => 'completed',
            'payment_status' => 'paid', 'paid_at' => now(), 'total_price' => $fee * 10,
            'service_price' => $fee * 10, 'platform_fee' => $fee, 'commission_rate' => 10,
            'commission_status' => CommissionLedger::OUTSTANDING, 'currency' => 'PHP',
            'payment_method' => 'on_hand', 'completed_at' => now(),
        ]);
    }

    private function admin(array $permissions): string
    {
        $catalog = ['view commissions', 'manage commissions', 'settle commissions', 'view support', 'manage support', 'view reports', 'manage reports'];
        foreach ([...$permissions, ...$catalog] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $role = Role::findOrCreate('finance-'.Str::random(5), 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user->createToken('admin')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** Each code PayMongo makes gets the next intent id: pi_qr_1, pi_qr_2… */
    private int $intents = 0;

    /** While true, PayMongo answers every call with a server error. */
    private bool $payMongoDown = false;

    /**
     * Faked once per test — Laravel keeps the first matching stub, so a second
     * fake would never take effect.
     */
    private function fakePayMongo(): void
    {
        Http::fake(function (Request $request) {
            if ($this->payMongoDown) {
                return Http::response(['errors' => [['code' => 'x', 'detail' => 'Service unavailable.']]], 500);
            }

            $url = $request->url();
            $intentId = 'pi_qr_'.$this->intents;

            return match (true) {
                str_ends_with($url, '/attach') => Http::response(['data' => [
                    'id' => $intentId,
                    'attributes' => [
                        'status' => 'awaiting_next_action',
                        'next_action' => ['type' => 'consume_qr', 'code' => ['image_url' => self::QR]],
                    ],
                ]]),
                str_ends_with($url, '/payment_intents') => Http::response(['data' => [
                    'id' => 'pi_qr_'.(++$this->intents),
                    'attributes' => ['status' => 'awaiting_payment_method', 'client_key' => 'ck_'.$this->intents],
                ]]),
                str_ends_with($url, '/payment_methods') => Http::response(['data' => ['id' => 'pm_qr_'.$this->intents]]),
                default => Http::response([], 404),
            };
        });
    }

    private function webhook(string $type, array $payment, string $eventId = 'evt_1', ?string $secret = null)
    {
        $payload = json_encode(['data' => ['id' => $eventId, 'attributes' => ['type' => $type, 'data' => $payment]]]);
        $timestamp = time();
        $digest = hash_hmac('sha256', $timestamp.'.'.$payload, $secret ?? self::WEBHOOK_SECRET);

        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$digest},li=wrong",
        ], $payload);
    }

    private function payment(string $intentId = 'pi_qr_1', int $amount = 2000, string $currency = 'PHP'): array
    {
        return ['id' => 'pay_qr_1', 'attributes' => [
            'payment_intent_id' => $intentId, 'amount' => $amount, 'currency' => $currency, 'status' => 'paid',
        ]];
    }

    public function test_the_qr_is_for_exactly_what_is_owed_and_settles_nothing_yet(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        $booking = $this->owed($profile, 20);
        $this->owed($profile, 15);

        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr', ['amount' => 1])
            ->assertCreated()
            ->assertJsonPath('data.method', 'qrph')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount_due', '35.00')
            ->assertJsonPath('data.qr_image', self::QR)
            ->assertJsonPath('data.bookings_count', 2);

        // The amount PayMongo was asked for is the server's, in centavos —
        // the `amount` the app sent is ignored.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payment_intents')
            && $request['data']['attributes']['amount'] === 3500
            && $request['data']['attributes']['currency'] === 'PHP'
            && $request['data']['attributes']['payment_method_allowed'] === ['qrph']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payment_methods')
            && $request['data']['attributes']['type'] === 'qrph'
            && $request['data']['attributes']['expiry_seconds'] === 900);

        $this->assertSame(CommissionLedger::OUTSTANDING, $booking->fresh()->commission_status);
        $this->as($token)->getJson('/api/client/v1/provider/commissions')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.payment_account.qr_payments_available', true)
            ->assertJsonPath('data.latest_remittance.qr_image', self::QR);
    }

    public function test_asking_again_returns_the_open_code_instead_of_a_second_one(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        $this->owed($profile);

        $first = $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated()->json('data.id');
        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')
            ->assertOk()
            ->assertJsonPath('data.id', $first);

        Http::assertSentCount(3);
        $this->assertSame(1, CommissionRemittance::count());
    }

    public function test_without_paymongo_keys_qr_payments_are_unavailable(): void
    {
        config(['payments.paymongo.secret_key' => null]);
        Http::fake();
        [$profile, , $token] = $this->provider();
        $this->owed($profile);

        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertStatus(503);
        $this->as($token)->getJson('/api/client/v1/provider/commissions')
            ->assertJsonPath('data.payment_account.qr_payments_available', false);
        Http::assertNothingSent();
    }

    public function test_nothing_owed_means_no_code(): void
    {
        $this->fakePayMongo();
        [, , $token] = $this->provider();

        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_an_older_gcash_reference_payment_waiting_blocks_a_qr_code(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        // As an older app version submitted it, before the GCash-reference way was retired.
        CommissionRemittance::create([
            'provider_profile_id' => $profile->id, 'method' => CommissionRemittance::METHOD_GCASH_REFERENCE,
            'amount_due' => 20, 'amount_sent' => 20, 'reference' => '2012345678901',
            'status' => CommissionRemittance::PENDING,
        ]);

        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_a_paid_webhook_settles_the_debt_and_lifts_the_block(): void
    {
        Notification::fake();
        $this->fakePayMongo();
        [$profile, $user, $token] = $this->provider();
        $booking = $this->owed($profile, 20);
        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated();

        // Waiting for the scan is PayMongo's job, not the reviewers'.
        $this->as($this->admin(['view commissions']))->getJson('/api/dashboard/attention')
            ->assertJsonPath('data.pending_commission_remittances', 0);

        $this->webhook('payment.paid', $this->payment())->assertOk();

        $remittance = CommissionRemittance::sole();
        $this->assertSame(CommissionRemittance::CONFIRMED, $remittance->status);
        $this->assertSame('pay_qr_1', $remittance->external_payment_id);
        $this->assertNull($remittance->reviewed_by);

        $settlement = CommissionSettlement::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame('qrph', $settlement->method);
        $this->assertSame($remittance->id, $settlement->commission_remittance_id);

        $this->as($token)->getJson('/api/client/v1/provider/commissions')
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.latest_remittance.status', 'confirmed')
            ->assertJsonPath('data.latest_remittance.qr_image', null);

        Notification::assertSentToTimes($user, CommissionRemittanceNotification::class, 1);
        Notification::assertNotSentTo($user, CommissionSettlementNotification::class);
    }

    public function test_a_redelivered_webhook_changes_nothing(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated();

        $this->webhook('payment.paid', $this->payment(), 'evt_1')->assertOk();
        $this->webhook('payment.paid', $this->payment(), 'evt_1')->assertOk();
        $this->webhook('payment.paid', $this->payment(), 'evt_2')->assertOk();

        $this->assertSame(1, CommissionSettlement::count());
    }

    public function test_a_payment_of_the_wrong_amount_or_currency_settles_nothing_and_goes_to_a_reviewer(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        $booking = $this->owed($profile, 20);
        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated();

        $this->webhook('payment.paid', $this->payment(amount: 100))->assertOk();
        $this->webhook('payment.paid', $this->payment(currency: 'USD'), 'evt_2')->assertOk();

        $this->assertSame(CommissionLedger::OUTSTANDING, $booking->fresh()->commission_status);
        $remittance = CommissionRemittance::sole();
        $this->assertSame(CommissionRemittance::PENDING, $remittance->status);
        $this->assertNotNull($remittance->failure_reason);

        $adminToken = $this->admin(['view commissions']);
        $this->as($adminToken)->getJson('/api/commission-remittances?status=pending')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.method', 'qrph');
        $this->as($adminToken)->getJson('/api/dashboard/attention')
            ->assertJsonPath('data.pending_commission_remittances', 1);
    }

    public function test_a_forged_webhook_is_refused(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        $booking = $this->owed($profile);
        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated();

        $this->webhook('payment.paid', $this->payment(), secret: 'not-the-secret')->assertStatus(401);

        $this->assertSame(CommissionLedger::OUTSTANDING, $booking->fresh()->commission_status);
        $this->assertSame(CommissionRemittance::PENDING, CommissionRemittance::sole()->status);
    }

    public function test_a_failed_payment_closes_the_code_and_a_new_one_can_be_made(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated();

        $this->webhook('payment.failed', ['id' => 'pay_x', 'attributes' => [
            'payment_intent_id' => 'pi_qr_1', 'last_payment_error' => ['message' => 'Declined by the bank.'],
        ]])->assertOk();

        $this->as($token)->getJson('/api/client/v1/provider/commissions')
            ->assertJsonPath('data.latest_remittance.status', 'failed')
            ->assertJsonPath('data.latest_remittance.failure_reason', 'Declined by the bank.');

        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated();
        $this->assertSame(2, CommissionRemittance::count());
    }

    public function test_an_unscanned_code_expires_and_a_late_payment_still_counts(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        $booking = $this->owed($profile);
        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated();

        $this->webhook('qrph.expired', ['id' => 'qr_x', 'attributes' => ['payment_intent_id' => 'pi_qr_1']])->assertOk();
        $this->assertSame(CommissionRemittance::EXPIRED, CommissionRemittance::sole()->status);

        // The money arrived after all: it settles what it was for.
        $this->webhook('payment.paid', $this->payment(), 'evt_late')->assertOk();
        $this->assertSame(CommissionRemittance::CONFIRMED, CommissionRemittance::sole()->status);
        $this->assertSame(CommissionLedger::SETTLED, $booking->fresh()->commission_status);
    }

    public function test_a_code_past_its_time_stops_blocking_a_new_one(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $first = $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated()->json('data.id');

        // 15 minutes, plus the two-minute grace for a payment in flight.
        $this->travel(18)->minutes();

        $this->as($token)->getJson("/api/client/v1/provider/commission-remittances/{$first}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.qr_image', null);

        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')
            ->assertCreated()
            ->assertJsonPath('data.qr_image', self::QR);
    }

    public function test_when_paymongo_fails_the_attempt_is_closed_and_can_be_retried(): void
    {
        $this->fakePayMongo();
        $this->payMongoDown = true;
        [$profile, , $token] = $this->provider();
        $this->owed($profile);

        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertStatus(502);
        $this->assertSame(CommissionRemittance::FAILED, CommissionRemittance::sole()->status);

        $this->payMongoDown = false;
        $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated();
    }

    public function test_a_provider_sees_only_their_own_payments(): void
    {
        $this->fakePayMongo();
        [$profile, , $token] = $this->provider();
        [, , $otherToken] = $this->provider();
        $this->owed($profile);
        $id = $this->as($token)->postJson('/api/client/v1/provider/commission-remittances/qr')->assertCreated()->json('data.id');

        $this->as($otherToken)->getJson("/api/client/v1/provider/commission-remittances/{$id}")->assertNotFound();
        $this->as($token)->getJson("/api/client/v1/provider/commission-remittances/{$id}")->assertOk();
    }
}
