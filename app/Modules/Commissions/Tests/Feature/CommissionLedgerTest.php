<?php

namespace App\Modules\Commissions\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionSettlement;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The commission is inclusive, so a provider paid in cash for a finished job
 * is holding SkillServe's share and owes it back. Until they remit it they
 * cannot take on new work.
 */
class CommissionLedgerTest extends TestCase
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

    /** @return array{0: User, 1: string} */
    private function customer(): array
    {
        $user = User::factory()->create([
            'email' => 'customer.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        return [$user, $user->createToken('client', ['client:auth'])->plainTextToken];
    }

    private function service(ProviderProfile $profile, float $price = 200): Service
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

    /** A finished job, priced so the commission is ₱20 of ₱200. */
    private function completedBooking(ProviderProfile $profile, User $client, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'service_id' => $this->service($profile)->id,
            'client_id' => $client->id,
            'provider_id' => $profile->id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)),
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total_price' => 200,
            'service_price' => 200,
            'platform_fee' => 20,
            'commission_rate' => 10,
            'commission_status' => CommissionLedger::PENDING,
            'currency' => 'PHP',
            'payment_method' => 'on_hand',
            'completed_at' => now(),
        ], $overrides));
    }

    /** @param array<int, string> $permissions */
    private function admin(array $permissions): array
    {
        // Policies look up names beyond the ones being granted, and Spatie
        // throws if a name does not exist at all. Production seeds the whole
        // catalog, so the relevant names are created here and only the
        // requested ones are granted.
        foreach ([...$permissions, 'manage bookings', 'manage commissions'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::findOrCreate('finance-'.Str::random(5));
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role->name);

        return [$user, $user->createToken('admin')->plainTextToken];
    }

    public function test_being_paid_makes_the_commission_outstanding(): void
    {
        [$profile, , $providerToken] = $this->provider();
        [$client] = $this->customer();
        $booking = $this->completedBooking($profile, $client);

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-received")
            ->assertOk();

        $booking->refresh();

        $this->assertSame('paid', $booking->payment_status);
        // The provider collected ₱200 in cash; ₱20 of it is SkillServe's.
        $this->assertSame(CommissionLedger::OUTSTANDING, $booking->commission_status);
    }

    public function test_a_booking_with_no_commission_settles_itself(): void
    {
        [$profile, , $providerToken] = $this->provider();
        [$client] = $this->customer();
        $booking = $this->completedBooking($profile, $client, ['platform_fee' => 0, 'commission_rate' => 0]);

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/payment-received")
            ->assertOk();

        // Nothing to chase, so the provider is not blocked over ₱0.
        $this->assertSame(CommissionLedger::SETTLED, $booking->refresh()->commission_status);
    }

    public function test_an_outstanding_commission_blocks_new_work(): void
    {
        [$profile, , $providerToken] = $this->provider();
        [$client] = $this->customer();

        $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);

        $pending = $this->completedBooking($profile, $client, [
            'status' => 'pending',
            'completed_at' => null,
            'commission_status' => CommissionLedger::PENDING,
        ]);

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$pending->id}/confirm")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Settle your outstanding commission before taking on new work.');

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

    public function test_a_provider_paid_up_front_can_still_start_that_job(): void
    {
        [$profile, , $providerToken] = $this->provider();
        [$client] = $this->customer();

        // The customer paid before the work began, so the provider is already
        // holding SkillServe's share of this very booking.
        $booking = $this->completedBooking($profile, $client, [
            'status' => 'confirmed',
            'completed_at' => null,
            'confirmed_at' => now(),
            'scheduled_date' => now()->addDay(),
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);

        // Blocking this would strand the customer and leave the provider
        // unable to finish the job that created the debt.
        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/start")
            ->assertOk();

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/complete")
            ->assertOk();
    }

    public function test_a_blocked_provider_can_still_clear_their_queue(): void
    {
        [$profile, , $providerToken] = $this->provider();
        [$client] = $this->customer();

        $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);

        $pending = $this->completedBooking($profile, $client, [
            'status' => 'pending',
            'completed_at' => null,
            'commission_status' => CommissionLedger::PENDING,
        ]);

        // Declining is how they say no; blocking it would trap the customer.
        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$pending->id}/decline", ['reason' => 'Unavailable'])
            ->assertOk();
    }

    public function test_the_customer_is_never_blocked_by_a_providers_debt(): void
    {
        [$profile] = $this->provider();
        [$client, $clientToken] = $this->customer();

        $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);

        $other = $this->provider();
        $service = $this->service($other[0]);

        $this->withToken($clientToken)
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => now()->addWeek()->format('Y-m-d H:i:s'),
            ])
            ->assertStatus(201);
    }

    public function test_settling_clears_the_debt_and_unblocks_the_provider(): void
    {
        [$profile, , $providerToken] = $this->provider();
        [$client] = $this->customer();
        [$actor, $adminToken] = $this->admin(['view commissions', 'settle commissions']);

        $booking = $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);
        $pending = $this->completedBooking($profile, $client, [
            'status' => 'pending', 'completed_at' => null,
            'commission_status' => CommissionLedger::PENDING,
        ]);

        $this->withToken($adminToken)
            ->patchJson("/api/commissions/{$booking->id}/settle", [
                'method' => 'gcash',
                'reference' => 'GC-12345',
            ])
            ->assertOk()
            ->assertJsonPath('data.commission_status', CommissionLedger::SETTLED)
            ->assertJsonPath('data.settlement.method', 'gcash')
            ->assertJsonPath('data.settlement.reference', 'GC-12345');

        // The amount comes from the booking, never from the request.
        $this->assertDatabaseHas('commission_settlements', [
            'booking_id' => $booking->id,
            'amount' => 20,
            'settled_by' => $actor->id,
        ]);

        $this->app['auth']->forgetGuards();

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$pending->id}/confirm")
            ->assertOk();
    }

    public function test_a_commission_cannot_be_settled_twice(): void
    {
        [$profile] = $this->provider();
        [$client] = $this->customer();
        [, $adminToken] = $this->admin(['settle commissions']);

        $booking = $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);

        $this->withToken($adminToken)
            ->patchJson("/api/commissions/{$booking->id}/settle", ['method' => 'cash'])
            ->assertOk();

        $this->withToken($adminToken)
            ->patchJson("/api/commissions/{$booking->id}/settle", ['method' => 'cash'])
            ->assertStatus(409);

        $this->assertSame(1, CommissionSettlement::query()->count());
    }

    public function test_waiving_requires_a_reason_and_is_audited(): void
    {
        [$profile] = $this->provider();
        [$client] = $this->customer();
        [$actor, $adminToken] = $this->admin(['settle commissions']);

        $booking = $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);

        $this->withToken($adminToken)
            ->patchJson("/api/commissions/{$booking->id}/waive", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->withToken($adminToken)
            ->patchJson("/api/commissions/{$booking->id}/waive", ['reason' => 'Goodwill after a dispute.'])
            ->assertOk()
            ->assertJsonPath('data.commission_status', CommissionLedger::WAIVED);

        $activity = Activity::query()->where('description', 'commission_waived')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
        $this->assertSame('Goodwill after a dispute.', $activity->properties['reason']);
    }

    public function test_cancelling_a_booking_voids_its_commission(): void
    {
        [$profile, , $providerToken] = $this->provider();
        [$client] = $this->customer();

        $booking = $this->completedBooking($profile, $client, [
            'status' => 'confirmed',
            'completed_at' => null,
            'confirmed_at' => now(),
            'scheduled_date' => now()->addWeek(),
        ]);

        $this->withToken($providerToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/cancel", ['reason' => 'Emergency.'])
            ->assertOk();

        $this->assertSame(CommissionLedger::VOIDED, $booking->refresh()->commission_status);
    }

    public function test_a_full_refund_voids_the_commission_but_a_partial_one_does_not(): void
    {
        [$profile] = $this->provider();
        [$client] = $this->customer();
        [, $adminToken] = $this->admin(['manage booking payments', 'view bookings']);

        $partial = $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);
        $full = $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);

        $this->withToken($adminToken)
            ->patchJson("/api/bookings/{$partial->id}/refund", ['amount' => 50, 'reason' => 'Partial.'])
            ->assertOk();

        $this->assertSame(CommissionLedger::OUTSTANDING, $partial->refresh()->commission_status);

        $this->withToken($adminToken)
            ->patchJson("/api/bookings/{$full->id}/refund", ['amount' => 200, 'reason' => 'Full refund.'])
            ->assertOk();

        $this->assertSame(CommissionLedger::VOIDED, $full->refresh()->commission_status);
    }

    public function test_the_ledger_requires_permission_and_separates_reading_from_settling(): void
    {
        [$profile] = $this->provider();
        [$client] = $this->customer();
        $booking = $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);

        $this->getJson('/api/commissions')->assertStatus(401);

        [, $readerToken] = $this->admin(['view commissions']);

        $this->withToken($readerToken)->getJson('/api/commissions')
            ->assertOk()
            ->assertJsonPath('meta.totals.outstanding', '20.00');

        // Reading the ledger must not let you write money off.
        $this->withToken($readerToken)
            ->patchJson("/api/commissions/{$booking->id}/waive", ['reason' => 'Nope.'])
            ->assertStatus(403);
    }

    public function test_a_provider_sees_what_they_owe(): void
    {
        [$profile, , $providerToken] = $this->provider();
        [$client] = $this->customer();

        $this->completedBooking($profile, $client, [
            'payment_status' => 'paid',
            'commission_status' => CommissionLedger::OUTSTANDING,
        ]);

        $this->withToken($providerToken)
            ->getJson('/api/client/v1/provider/commissions')
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason', 'outstanding_commission')
            ->assertJsonPath('data.outstanding_total', '20.00')
            ->assertJsonPath('data.outstanding_count', 1)
            ->assertJsonPath('data.outstanding.0.commission_amount', '20.00');
    }

    public function test_a_provider_with_nothing_owed_is_eligible(): void
    {
        [, , $providerToken] = $this->provider();

        $this->withToken($providerToken)
            ->getJson('/api/client/v1/provider/commissions')
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.outstanding_total', '0.00');
    }
}
