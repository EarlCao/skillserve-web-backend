<?php

namespace App\Modules\Payments\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SkillServe is never in the payment path.
 *
 * The customer pays the provider directly — GCash to the provider's own
 * number, or cash on the job — and the provider then owes SkillServe its
 * commission, which they must remit to keep taking work. See ADR-021.
 */
class DirectPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionTier::create([
            'name' => 'Standard', 'min_amount' => 0, 'max_amount' => null,
            'percentage' => 10, 'is_active' => true,
        ]);
    }

    /** @return array{0: ProviderProfile, 1: User, 2: Booking, 3: string} */
    private function scenario(array $profile = [], array $booking = []): array
    {
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active', 'email' => 'p'.Str::random(6).'@t.test']);
        $prof = ProviderProfile::create(array_merge([
            'user_id' => $providerUser->id,
            'business_name' => 'Juan Aircon',
            'verification_status' => 'verified',
        ], $profile));

        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active', 'email' => 'c'.Str::random(6).'@t.test']);

        $service = Service::create([
            'provider_id' => $prof->id,
            'category_id' => ServiceCategory::create(['name' => 'C'.Str::random(5), 'status' => 'enabled'])->id,
            'title' => 'Aircon Cleaning', 'description' => 'D', 'price' => 200, 'price_type' => 'fixed',
            'duration' => '1 hour', 'currency' => 'PHP', 'status' => 'published',
            'approval_status' => 'approved', 'is_hidden' => false,
        ]);

        $row = Booking::create(array_merge([
            'service_id' => $service->id, 'client_id' => $client->id, 'provider_id' => $prof->id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)), 'status' => 'confirmed',
            'payment_status' => 'unpaid', 'total_price' => 200, 'service_price' => 200,
            'platform_fee' => 20, 'commission_rate' => 10, 'commission_status' => 'pending',
            'currency' => 'PHP', 'payment_method' => 'gcash', 'confirmed_at' => now(),
        ], $booking));

        return [$prof, $client, $row, $client->createToken('client', ['client:auth'])->plainTextToken];
    }

    public function test_gcash_never_routes_to_a_collecting_gateway(): void
    {
        // Even with credentials present: collecting the booking total would
        // make SkillServe owe every provider their share.
        config(['payments.paymongo.secret_key' => 'sk_test_whatever']);

        $this->assertSame('manual', config('payments.gateways.gcash'));
        $this->assertSame('manual', config('payments.gateways.on_hand'));
    }

    public function test_paying_online_is_refused_for_both_methods(): void
    {
        Http::fake();

        foreach (['gcash', 'on_hand'] as $method) {
            [, , $booking, $token] = $this->scenario(
                ['gcash_number' => '09171234567', 'gcash_name' => 'Juan Dela Cruz'],
                ['payment_method' => $method],
            );

            $this->withToken($token)
                ->postJson("/api/client/v1/bookings/{$booking->id}/pay")
                ->assertStatus(422);

            $this->app['auth']->forgetGuards();
        }

        // Nothing reached a payment provider.
        Http::assertNothingSent();
    }

    public function test_the_customer_is_shown_where_to_send_the_money(): void
    {
        [, , $booking, $token] = $this->scenario(
            ['gcash_number' => '09171234567', 'gcash_name' => 'Juan Dela Cruz'],
        );

        $this->withToken($token)
            ->getJson("/api/client/v1/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_instructions.gcash_number', '09171234567')
            ->assertJsonPath('data.payment_instructions.gcash_name', 'Juan Dela Cruz')
            ->assertJsonPath('data.payment_instructions.amount', '200.00')
            ->assertJsonPath('data.payment_instructions.reference', $booking->booking_number);
    }

    public function test_a_provider_without_gcash_details_says_so_rather_than_showing_nothing(): void
    {
        [, , $booking, $token] = $this->scenario();

        $this->withToken($token)
            ->getJson("/api/client/v1/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_instructions.gcash_number', null)
            ->assertJsonPath('data.payment_instructions.note', 'This provider has not added their GCash details yet. Message them to arrange payment.');
    }

    public function test_payment_details_are_not_handed_out_once_the_job_is_settled(): void
    {
        [, , $paid, $token] = $this->scenario(
            ['gcash_number' => '09171234567', 'gcash_name' => 'Juan Dela Cruz'],
            ['payment_status' => 'paid', 'paid_at' => now()],
        );

        $this->withToken($token)
            ->getJson("/api/client/v1/bookings/{$paid->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.payment_instructions');
    }

    public function test_an_on_hand_booking_gets_no_gcash_instructions(): void
    {
        [, , $booking, $token] = $this->scenario(
            ['gcash_number' => '09171234567', 'gcash_name' => 'Juan Dela Cruz'],
            ['payment_method' => 'on_hand'],
        );

        $this->withToken($token)
            ->getJson("/api/client/v1/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.payment_instructions');
    }

    public function test_the_public_catalog_never_exposes_payout_details(): void
    {
        [$profile] = $this->scenario(['gcash_number' => '09171234567', 'gcash_name' => 'Juan Dela Cruz']);

        $response = $this->getJson("/api/client/v1/providers/{$profile->id}")->assertOk();

        $this->assertStringNotContainsString('09171234567', $response->getContent());
        $this->assertStringNotContainsString('gcash_number', $response->getContent());
    }

    public function test_a_gcash_job_leaves_the_commission_outstanding_exactly_like_cash(): void
    {
        [$profile, , $booking] = $this->scenario(
            ['gcash_number' => '09171234567', 'gcash_name' => 'Juan Dela Cruz'],
            ['status' => 'completed', 'completed_at' => now()],
        );

        $providerToken = $profile->user->createToken('provider', ['client:auth'])->plainTextToken;

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-received", [
                'payment_reference' => 'GCASH-123456',
            ])
            ->assertOk();

        $booking->refresh();

        // The provider received the full ₱200 and now owes the ₱20 back.
        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame(CommissionLedger::OUTSTANDING, $booking->commission_status);
        $this->assertSame('20.00', (string) $booking->platform_fee);
    }

    public function test_a_provider_saves_and_normalises_their_gcash_number(): void
    {
        [$profile] = $this->scenario();
        $token = $profile->user->createToken('provider', ['client:auth'])->plainTextToken;

        foreach (['+63 917 123 4567', '639171234567', '9171234567', '0917-123-4567'] as $typed) {
            $this->withToken($token)
                ->patchJson('/api/client/v1/provider/profile', [
                    'gcash_number' => $typed,
                    'gcash_name' => 'Juan Dela Cruz',
                ])
                ->assertOk()
                ->assertJsonPath('data.gcash_number', '09171234567')
                ->assertJsonPath('data.can_receive_gcash', true);
        }
    }

    public function test_a_provider_can_remove_their_gcash_details(): void
    {
        // A provider who stops using GCash must be able to stop advertising a
        // number, or customers keep being sent to a dead one. Both columns end
        // up null, not an empty string, so `canReceiveGcash()` and the
        // nullable rules agree.
        [$profile] = $this->scenario(['gcash_number' => '09171234567', 'gcash_name' => 'Juan Dela Cruz']);
        $token = $profile->user->createToken('provider', ['client:auth'])->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/profile', [
                'gcash_number' => null,
                'gcash_name' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.gcash_number', null)
            ->assertJsonPath('data.gcash_name', null)
            ->assertJsonPath('data.can_receive_gcash', false);

        $profile->refresh();
        $this->assertNull($profile->gcash_number);
        $this->assertNull($profile->gcash_name);
    }

    public function test_an_invalid_gcash_number_is_refused(): void
    {
        [$profile] = $this->scenario();
        $token = $profile->user->createToken('provider', ['client:auth'])->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/profile', ['gcash_number' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gcash_number');
    }
}
