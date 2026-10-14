<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingRefund;
use App\Modules\Bookings\Notifications\BookingRefundNotification;
use App\Modules\Notifications\Notifications\AdminAlertNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** A cancelled booking's payment goes back to the customer. */
class BookingRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(BookingRefund::DISK);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_provider_returns_a_downpayment_and_the_customer_confirms_it(): void
    {
        Notification::fake();
        [$profile, $providerUser, $providerToken] = $this->provider();
        $customer = $this->customer();
        $booking = $this->booking($customer, $profile, ['payment_status' => 'partially_paid', 'amount_paid' => 500]);

        // The provider calls it off: everything paid must go back.
        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/cancel", ['reason' => 'I am unwell and cannot come.'])
            ->assertOk()
            ->assertJsonPath('data.refund.amount', '500.00')
            ->assertJsonPath('data.refund.status', 'due')
            ->assertJsonPath('data.refund.is_overdue', false);
        $refund = BookingRefund::query()->sole();
        $this->assertTrue($refund->due_by->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
        Notification::assertSentTo($customer, BookingRefundNotification::class, fn ($n) => $n->toArray($customer)['action'] === 'opened');
        Notification::assertSentTo($providerUser, BookingRefundNotification::class);

        // Proof is required, then the provider sends it.
        $this->withToken($providerToken)
            ->postJson("/api/client/v1/provider/bookings/{$booking->id}/refund", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
        $this->withToken($providerToken)
            ->post("/api/client/v1/provider/bookings/{$booking->id}/refund", ['reference' => '5001', 'image' => $this->screenshot()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'sent');
        Notification::assertSentTo($customer, BookingRefundNotification::class, fn ($n) => $n->toArray($customer)['action'] === 'sent');

        // The customer sees the proof and confirms the money arrived.
        Auth::forgetGuards();
        $customerToken = $this->token($customer);
        $this->withToken($customerToken)->get("/api/client/v1/bookings/{$booking->id}/refund/proof")->assertOk();
        $this->withToken($customerToken)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/refund/confirm")
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'refunded')
            ->assertJsonPath('data.refunded_amount', '500.00');
    }

    public function test_a_customer_who_cancels_late_leaves_the_provider_the_fee(): void
    {
        Setting::query()->create(['group' => 'booking', 'name' => 'client_cancellation_fee_percent', 'payload' => 10]);
        [$profile] = $this->provider();
        $customer = $this->customer();
        // Starts within the 24-hour window, so cancelling now is late.
        $booking = $this->booking($customer, $profile, [
            'payment_status' => 'paid', 'amount_paid' => 1500, 'scheduled_date' => now()->addHours(5), 'scheduled_end_date' => now()->addHours(6),
        ]);

        $this->withToken($this->token($customer))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/cancel", ['reason' => 'Plans changed.'])
            ->assertOk()
            ->assertJsonPath('data.refund.amount', '1350.00');
    }

    public function test_nothing_paid_means_nothing_to_return(): void
    {
        [$profile, , $providerToken] = $this->provider();
        $booking = $this->booking($this->customer(), $profile);

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/cancel", ['reason' => 'I am unwell and cannot come.'])
            ->assertOk()
            ->assertJsonPath('data.refund', null);
        $this->assertSame(0, BookingRefund::query()->count());
    }

    public function test_an_overdue_refund_blocks_the_provider_and_alerts_administrators_once(): void
    {
        Notification::fake();
        $admin = $this->administrator(['view bookings']);
        [$profile, $providerUser, $providerToken] = $this->provider();
        $booking = $this->booking($this->customer(), $profile, ['status' => 'cancelled', 'amount_paid' => 500, 'payment_status' => 'partially_paid']);
        BookingRefund::create(['booking_id' => $booking->id, 'amount' => 500, 'status' => 'due', 'due_by' => now()->addDay()]);
        $request = $this->booking($this->customer(), $profile, ['status' => 'pending']);

        // Within the deadline nothing is blocked.
        $this->withToken($providerToken)->getJson('/api/client/v1/transaction-eligibility')
            ->assertJsonPath('data.eligible', true);

        $this->travel(2)->days();
        $this->withToken($providerToken)->getJson('/api/client/v1/transaction-eligibility')
            ->assertJsonPath('data.reason', 'refund_overdue')
            ->assertJsonPath('data.refunds_overdue', 1);
        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$request->id}/confirm")
            ->assertForbidden()
            ->assertJsonPath('errors.refund.0', "A cancelled booking's payment has not been returned to the customer.");

        Artisan::call('bookings:escalate-overdue-refunds');
        Artisan::call('bookings:escalate-overdue-refunds');
        Notification::assertSentToTimes($admin, AdminAlertNotification::class, 1);
        Notification::assertSentToTimes($providerUser, BookingRefundNotification::class, 1);
    }

    public function test_not_received_goes_to_administrators_who_settle_it(): void
    {
        $admin = $this->administrator(['view bookings', 'manage booking payments']);
        [$profile, , $providerToken] = $this->provider();
        $customer = $this->customer();
        $booking = $this->booking($customer, $profile, ['status' => 'cancelled', 'amount_paid' => 800, 'payment_status' => 'partially_paid']);
        BookingRefund::create(['booking_id' => $booking->id, 'amount' => 800, 'status' => 'sent', 'due_by' => now()->addDay(), 'proof_path' => 'refunds/x.png', 'sent_at' => now()]);
        $customerToken = $this->token($customer);

        $this->withToken($customerToken)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/refund/not-received", ['note' => 'short'])
            ->assertUnprocessable();
        $this->withToken($customerToken)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/refund/not-received", ['note' => 'Nothing arrived in my GCash at all.'])
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'not_received');
        $this->assertSame('refund', $admin->notifications()->sole()->data['kind']);

        // Held back until it is settled.
        Auth::forgetGuards();
        $this->withToken($providerToken)->getJson('/api/client/v1/transaction-eligibility')
            ->assertJsonPath('data.reason', 'refund_overdue');

        // The administrator finds it was not returned: still blocked.
        Auth::forgetGuards();
        $adminToken = $admin->createToken('admin')->plainTextToken;
        $this->withToken($adminToken)
            ->patchJson("/api/bookings/{$booking->id}/refund/resolve", ['resolution' => 'not_returned', 'note' => 'No transfer found.'])
            ->assertOk()
            ->assertJsonPath('data.refund.resolution', 'not_returned');
        Auth::forgetGuards();
        $this->withToken($providerToken)->getJson('/api/client/v1/transaction-eligibility')
            ->assertJsonPath('data.reason', 'refund_overdue');

        // Later proof it was returned: recorded, and the provider is free again.
        Auth::forgetGuards();
        $this->withToken($adminToken)
            ->patchJson("/api/bookings/{$booking->id}/refund/resolve", ['resolution' => 'returned', 'note' => 'Bank record shows the transfer.'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'refunded');
        Auth::forgetGuards();
        $this->withToken($providerToken)->getJson('/api/client/v1/transaction-eligibility')
            ->assertJsonPath('data.eligible', true);
    }

    public function test_only_the_two_parties_act_on_a_refund(): void
    {
        [$profile] = $this->provider();
        [, , $otherProviderToken] = $this->provider();
        $customer = $this->customer();
        $booking = $this->booking($customer, $profile, ['status' => 'cancelled', 'amount_paid' => 500, 'payment_status' => 'partially_paid']);
        BookingRefund::create(['booking_id' => $booking->id, 'amount' => 500, 'status' => 'due', 'due_by' => now()->addDay()]);

        $this->withToken($otherProviderToken)
            ->post("/api/client/v1/provider/bookings/{$booking->id}/refund", ['image' => $this->screenshot()], ['Accept' => 'application/json'])
            ->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($this->token($this->customer()))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/refund/confirm")
            ->assertForbidden();
        // The customer cannot confirm what was not sent yet.
        Auth::forgetGuards();
        $this->withToken($this->token($customer))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/refund/confirm")
            ->assertStatus(409);
    }

    public function test_administrators_list_bookings_with_a_refund_owed(): void
    {
        $admin = $this->administrator(['view bookings']);
        [$profile] = $this->provider();
        $owed = $this->booking($this->customer(), $profile, ['status' => 'cancelled', 'amount_paid' => 500]);
        BookingRefund::create(['booking_id' => $owed->id, 'amount' => 500, 'status' => 'sent', 'due_by' => now()->addDay()]);
        $settled = $this->booking($this->customer(), $profile, ['status' => 'cancelled', 'amount_paid' => 500]);
        BookingRefund::create(['booking_id' => $settled->id, 'amount' => 500, 'status' => 'confirmed', 'due_by' => now()->addDay()]);

        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->getJson('/api/bookings?payment_status=refund_owed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owed->id);
    }

    private function screenshot(): UploadedFile
    {
        return UploadedFile::fake()->image('refund.png', 600, 1200);
    }

    private function token(User $user): string
    {
        return $user->createToken('client-test', ['client:auth'])->plainTextToken;
    }

    /** @param  array<int, string>  $permissions */
    private function administrator(array $permissions): User
    {
        foreach (['manage bookings', 'view bookings', 'manage booking payments', ...$permissions] as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::findOrCreate('refunds-'.Str::random(6));
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user->fresh();
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
            'booking_number' => 'BK-RF-'.Str::upper(Str::random(10)),
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'payment_method' => 'gcash',
            'total_price' => 1500,
            'service_price' => 1500,
            'platform_fee' => 150,
            'commission_status' => 'pending',
            'currency' => 'PHP',
            'scheduled_date' => now()->addDays(5),
            'scheduled_end_date' => now()->addDays(5)->addHour(),
        ], $attributes));
    }
}
