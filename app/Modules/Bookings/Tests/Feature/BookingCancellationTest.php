<?php

namespace App\Modules\Bookings\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BookingCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_unpaid_cancellation_remains_unpaid_without_refund_processing(): void
    {
        [$token, $booking] = $this->createBooking('unpaid');

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'No longer needed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.cancellation_payment_policy', 'unpaid_no_refund_due');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_paid_cancellation_keeps_payment_state_and_does_not_claim_a_refund(): void
    {
        [$token, $booking] = $this->createBooking('paid');

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.cancellation_payment_policy', 'payment_unchanged_refund_not_processed');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'payment_status' => 'paid']);
    }

    /** @return array{0: string, 1: Booking} */
    private function createBooking(string $paymentStatus): array
    {
        Permission::findOrCreate('manage bookings');
        Permission::findOrCreate('cancel bookings');
        $role = Role::create(['name' => 'booking-canceller-'.uniqid()]);
        $role->givePermissionTo(['manage bookings', 'cancel bookings']);
        $admin = User::factory()->create([
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $admin->assignRole($role);

        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'Cancellation Provider']);
        $category = ServiceCategory::create(['name' => 'Cancellation '.uniqid(), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Cancellation Service',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
        $booking = Booking::create([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-CANCEL-'.uniqid(),
            'status' => 'pending',
            'payment_status' => $paymentStatus,
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 0,
            'currency' => 'USD',
        ]);

        return [$admin->createToken('test')->plainTextToken, $booking];
    }
}
