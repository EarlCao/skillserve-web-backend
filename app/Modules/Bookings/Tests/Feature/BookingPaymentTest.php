<?php

namespace App\Modules\Bookings\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Settlement is recorded by hand: a provider confirms payment for a job
 * they completed, and an administrator can mark any payable booking paid
 * or record a refund.
 */
class BookingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $providerUser;

    private ProviderProfile $provider;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $this->providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $this->provider = ProviderProfile::create([
            'user_id' => $this->providerUser->id,
            'business_name' => 'Payment Provider',
            'verification_status' => 'verified',
        ]);
        $category = ServiceCategory::create(['name' => 'Payments '.Str::random(5), 'status' => 'enabled']);
        $this->service = Service::create([
            'provider_id' => $this->provider->id,
            'category_id' => $category->id,
            'title' => 'Aircon Cleaning',
            'price' => 1500,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
    }

    public function test_an_admin_marks_a_booking_paid_and_both_parties_are_told(): void
    {
        Notification::fake();
        [$admin, $token] = $this->admin(['manage booking payments']);
        $booking = $this->booking(['status' => 'completed']);

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/mark-paid", ['payment_reference' => 'GCASH-123'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_reference', 'GCASH-123')
            ->assertJsonPath('data.payment_recorded_by.id', $admin->id);

        $this->assertNotNull($booking->fresh()->paid_at);
        Notification::assertSentTo($this->client, BookingStatusNotification::class, fn ($n) => $n->toArray($this->client)['action'] === 'paid');
        Notification::assertSentTo($this->providerUser, BookingStatusNotification::class);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $booking->id,
            'description' => 'booking_payment_recorded',
            'causer_id' => $admin->id,
        ]);
    }

    public function test_marking_paid_is_refused_twice_and_for_pending_or_cancelled_bookings(): void
    {
        [, $token] = $this->admin(['manage booking payments']);

        $this->withToken($token)
            ->patchJson('/api/bookings/'.$this->booking(['payment_status' => 'paid'])->id.'/mark-paid')
            ->assertStatus(409);

        foreach (['pending', 'cancelled'] as $status) {
            $booking = $this->booking(['status' => $status]);

            $this->withToken($token)
                ->patchJson("/api/bookings/{$booking->id}/mark-paid")
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');

            $this->assertSame('unpaid', $booking->fresh()->payment_status);
        }
    }

    public function test_payment_actions_need_the_payments_permission(): void
    {
        [, $token] = $this->admin(['view bookings']);
        $booking = $this->booking(['status' => 'completed']);

        $this->withToken($token)->patchJson("/api/bookings/{$booking->id}/mark-paid")->assertForbidden();
        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/refund", ['amount' => 100, 'reason' => 'Goodwill refund.'])
            ->assertForbidden();

        $this->assertSame('unpaid', $booking->fresh()->payment_status);
    }

    public function test_refunds_go_partial_then_full_and_never_past_the_total(): void
    {
        Notification::fake();
        [, $token] = $this->admin(['manage booking payments']);
        $booking = $this->booking(['status' => 'completed', 'payment_status' => 'paid']);

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/refund", ['amount' => 500, 'reason' => 'Job finished early.'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'partially_refunded')
            ->assertJsonPath('data.refunded_amount', '500.00')
            ->assertJsonPath('data.refund_reason', 'Job finished early.');

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/refund", ['amount' => 1000.01, 'reason' => 'Too much.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/refund", ['amount' => 1000, 'reason' => 'Customer unhappy.'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'refunded')
            ->assertJsonPath('data.refunded_amount', '1500.00');

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/refund", ['amount' => 1, 'reason' => 'Nothing left.'])
            ->assertStatus(422);

        Notification::assertSentTo($this->client, BookingStatusNotification::class, fn ($n) => $n->toArray($this->client)['action'] === 'refunded');
    }

    public function test_a_refund_needs_a_paid_booking_a_positive_amount_and_a_reason(): void
    {
        [, $token] = $this->admin(['manage booking payments']);
        $booking = $this->booking(['status' => 'completed']);

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/refund", ['amount' => 100, 'reason' => 'Not paid yet.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_status');

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/refund", ['amount' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'reason']);
    }

    public function test_the_provider_confirms_payment_for_a_completed_job_and_the_customer_is_told(): void
    {
        Notification::fake();
        $booking = $this->booking(['status' => 'completed']);

        $this->withToken($this->providerToken())
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-received")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $booking->refresh();
        $this->assertSame($this->providerUser->id, $booking->payment_recorded_by);
        $this->assertNotNull($booking->paid_at);
        Notification::assertSentTo($this->client, BookingStatusNotification::class);
        Notification::assertNotSentTo($this->providerUser, BookingStatusNotification::class);
    }

    public function test_the_provider_can_only_confirm_payment_once_for_their_own_completed_job(): void
    {
        $token = $this->providerToken();

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/bookings/'.$this->booking(['status' => 'active'])->id.'/payment-received')
            ->assertStatus(422);

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/bookings/'.$this->booking(['status' => 'completed', 'payment_status' => 'paid'])->id.'/payment-received')
            ->assertStatus(409);

        $otherUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        ProviderProfile::create(['user_id' => $otherUser->id, 'business_name' => 'Other']);
        $booking = $this->booking(['status' => 'completed']);

        $this->app['auth']->forgetGuards();
        $this->withToken($otherUser->createToken('p', ['client:auth'])->plainTextToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-received")
            ->assertForbidden();

        $this->assertSame('unpaid', $booking->fresh()->payment_status);
    }

    public function test_the_customer_sees_the_recorded_payment(): void
    {
        $booking = $this->booking(['status' => 'completed', 'payment_status' => 'paid', 'paid_at' => now()]);

        $this->withToken($this->client->createToken('c', ['client:auth'])->plainTextToken)
            ->getJson("/api/client/v1/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.refunded_amount', '0.00');
        $this->assertNotNull($booking->fresh()->paid_at);
    }

    /** @return array{0: User, 1: string} */
    private function admin(array $permissions): array
    {
        foreach (['manage bookings', 'view bookings', 'manage booking payments'] as $name) {
            Permission::findOrCreate($name);
        }
        $role = Role::create(['name' => 'payments-'.Str::random(6)]);
        $role->givePermissionTo($permissions);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return [$admin, $admin->createToken('test')->plainTextToken];
    }

    private function providerToken(): string
    {
        return $this->providerUser->createToken('p', ['client:auth'])->plainTextToken;
    }

    private function booking(array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'provider_id' => $this->provider->id,
            'booking_number' => 'BK-PAY-'.Str::random(10),
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'total_price' => 1500,
            'service_price' => 1500,
            'platform_fee' => 150,
            'currency' => 'PHP',
            'scheduled_date' => now()->subDay(),
            'scheduled_end_date' => now()->subDay()->addHours(2),
        ], $attributes));
    }
}
