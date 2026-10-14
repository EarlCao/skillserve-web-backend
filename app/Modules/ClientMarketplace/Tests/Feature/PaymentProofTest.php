<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingPaymentProof;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\Bookings\Notifications\PaymentProofNotification;
use App\Modules\ClientMarketplace\Events\ClientBookingChanged;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** GCash payment proofs: downpayment, the rest, confirm and reject. */
class PaymentProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(BookingPaymentProof::DISK);
    }

    public function test_a_downpayment_then_the_rest_settles_the_booking(): void
    {
        Notification::fake();
        Event::fake([ClientBookingChanged::class]);
        [$profile, $providerUser, $providerToken] = $this->provider();
        $customer = $this->customer();
        $booking = $this->booking($customer, $profile, ['status' => 'confirmed']);
        $customerToken = $this->token($customer);

        // A ₱500 downpayment before the work.
        $proofId = $this->withToken($customerToken)
            ->post("/api/client/v1/bookings/{$booking->id}/payment-proofs", [
                'amount' => 500, 'reference' => '1009 876 543', 'image' => $this->screenshot(),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.payment_proofs.0.status', 'pending')
            ->assertJsonPath('data.payment_proofs.0.amount', '500.00')
            ->assertJsonPath('data.balance_due', '1500.00')
            ->json('data.payment_proofs.0.id');
        Notification::assertSentTo($providerUser, PaymentProofNotification::class);
        // Both parties' open screens are told, whatever their notification settings.
        Event::assertDispatched(ClientBookingChanged::class, fn ($e) => $e->userId === $providerUser->id && $e->bookingId === $booking->id);
        Event::assertDispatched(ClientBookingChanged::class, fn ($e) => $e->userId === $customer->id);

        // The provider sees the screenshot and confirms it.
        Auth::forgetGuards();
        $this->withToken($providerToken)
            ->get("/api/client/v1/provider/bookings/{$booking->id}/payment-proofs/{$proofId}/image")
            ->assertOk();
        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-proofs/{$proofId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'partially_paid')
            ->assertJsonPath('data.amount_paid', '500.00')
            ->assertJsonPath('data.balance_due', '1000.00');
        Notification::assertSentTo($customer, BookingStatusNotification::class, fn ($n) => $n->toArray($customer)['action'] === 'partially_paid');
        $this->assertSame('pending', $booking->fresh()->commission_status);

        // The rest after the work; the instructions now ask for the balance.
        $booking->update(['status' => 'completed']);
        Auth::forgetGuards();
        $this->withToken($customerToken)->getJson("/api/client/v1/bookings/{$booking->id}")
            ->assertJsonPath('data.payment_instructions.amount', '1000.00');
        $restId = $this->withToken($customerToken)
            ->post("/api/client/v1/bookings/{$booking->id}/payment-proofs", ['amount' => 1000, 'image' => $this->screenshot()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.payment_proofs.0.id');

        Auth::forgetGuards();
        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-proofs/{$restId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.amount_paid', '1500.00')
            ->assertJsonPath('data.balance_due', '0.00');
        // Fully paid: SkillServe's share is now owed, as with any paid booking.
        $this->assertSame('outstanding', $booking->fresh()->commission_status);

        // Nothing left to pay.
        Auth::forgetGuards();
        $this->withToken($customerToken)
            ->post("/api/client/v1/bookings/{$booking->id}/payment-proofs", ['amount' => 1, 'image' => $this->screenshot()], ['Accept' => 'application/json'])
            ->assertStatus(409);
    }

    public function test_a_rejected_proof_tells_the_customer_why_and_records_nothing(): void
    {
        Notification::fake();
        [$profile, , $providerToken] = $this->provider();
        $customer = $this->customer();
        $booking = $this->booking($customer, $profile, ['status' => 'active']);

        $proofId = $this->withToken($this->token($customer))
            ->post("/api/client/v1/bookings/{$booking->id}/payment-proofs", ['amount' => 1500, 'image' => $this->screenshot()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.payment_proofs.0.id');

        Auth::forgetGuards();
        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-proofs/{$proofId}/reject", ['reason' => ''])
            ->assertUnprocessable();
        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-proofs/{$proofId}/reject", ['reason' => 'Nothing reached my GCash.'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payment_proofs.0.status', 'rejected')
            ->assertJsonPath('data.payment_proofs.0.review_note', 'Nothing reached my GCash.');
        Notification::assertSentTo($customer, PaymentProofNotification::class, fn ($n) => $n->toArray($customer)['action'] === 'rejected');

        // Reviewed once only.
        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-proofs/{$proofId}/confirm")
            ->assertStatus(409);
    }

    public function test_what_a_proof_may_be_sent_for(): void
    {
        [$profile] = $this->provider();
        $customer = $this->customer();
        $token = $this->token($customer);
        $send = fn (Booking $booking, float $amount = 100) => $this->withToken($token)
            ->post("/api/client/v1/bookings/{$booking->id}/payment-proofs", ['amount' => $amount, 'image' => $this->screenshot()], ['Accept' => 'application/json']);

        // Not accepted yet, or paid in person: no proof.
        $send($this->booking($customer, $profile, ['status' => 'pending']))->assertUnprocessable();
        $send($this->booking($customer, $profile, ['status' => 'confirmed', 'payment_method' => 'on_hand']))->assertUnprocessable();

        $booking = $this->booking($customer, $profile, ['status' => 'confirmed']);
        $send($booking, 1500.01)->assertUnprocessable();
        $this->withToken($token)
            ->postJson("/api/client/v1/bookings/{$booking->id}/payment-proofs", ['amount' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);

        // One proof waits at a time.
        $send($booking)->assertCreated();
        $send($booking)->assertStatus(409);

        // Someone else's booking.
        Auth::forgetGuards();
        $this->withToken($this->token($this->customer()))
            ->post("/api/client/v1/bookings/{$booking->id}/payment-proofs", ['amount' => 100, 'image' => $this->screenshot()], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_only_the_bookings_provider_reviews_or_sees_a_proof(): void
    {
        [$profile] = $this->provider();
        [, , $otherToken] = $this->provider();
        $customer = $this->customer();
        $booking = $this->booking($customer, $profile, ['status' => 'confirmed']);
        $proofId = $this->withToken($this->token($customer))
            ->post("/api/client/v1/bookings/{$booking->id}/payment-proofs", ['amount' => 100, 'image' => $this->screenshot()], ['Accept' => 'application/json'])
            ->json('data.payment_proofs.0.id');

        Auth::forgetGuards();
        $this->withToken($otherToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-proofs/{$proofId}/confirm")
            ->assertForbidden();
        $this->withToken($otherToken)
            ->get("/api/client/v1/provider/bookings/{$booking->id}/payment-proofs/{$proofId}/image", ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->assertSame(BookingPaymentProof::PENDING, BookingPaymentProof::query()->value('status'));
    }

    public function test_recording_the_rest_in_cash_settles_a_partly_paid_booking(): void
    {
        [$profile, , $providerToken] = $this->provider();
        $customer = $this->customer();
        $booking = $this->booking($customer, $profile, [
            'status' => 'completed', 'payment_status' => 'partially_paid', 'amount_paid' => 500,
        ]);

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-received")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.amount_paid', '1500.00');
    }

    private function screenshot(): UploadedFile
    {
        return UploadedFile::fake()->image('gcash.png', 600, 1200);
    }

    private function token(User $user): string
    {
        return $user->createToken('client-test', ['client:auth'])->plainTextToken;
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
            'business_name' => 'Provider '.Str::random(5),
            'verification_status' => 'verified',
        ]);

        return [$profile, $user, $this->token($user)];
    }

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'client.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    private function booking(User $customer, ProviderProfile $profile, array $attributes = []): Booking
    {
        $category = ServiceCategory::create(['name' => 'Cleaning '.Str::random(5), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $profile->id,
            'category_id' => $category->id,
            'title' => 'Deep Cleaning',
            'price' => 1500,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
            'is_hidden' => false,
        ]);

        return Booking::create(array_merge([
            'service_id' => $service->id,
            'client_id' => $customer->id,
            'provider_id' => $profile->id,
            'booking_number' => 'BK-PAY-'.Str::upper(Str::random(10)),
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'payment_method' => 'gcash',
            'total_price' => 1500,
            'service_price' => 1500,
            'platform_fee' => 150,
            'commission_status' => 'pending',
            'currency' => 'PHP',
            'scheduled_date' => now()->addDay(),
            'scheduled_end_date' => now()->addDay()->addHour(),
        ], $attributes));
    }
}
