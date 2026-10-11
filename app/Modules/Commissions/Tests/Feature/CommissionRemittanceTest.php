<?php

namespace App\Modules\Commissions\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionRemittance;
use App\Modules\Commissions\Models\CommissionSettlement;
use App\Modules\Commissions\Notifications\CommissionRemittanceNotification;
use App\Modules\Commissions\Notifications\CommissionSettlementNotification;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Commissions\Services\CommissionRemittanceService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A provider pays SkillServe's GCash outside the app and submits the reference
 * number; the commission is only settled once an administrator confirms the
 * money arrived.
 */
class CommissionRemittanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake(CommissionRemittanceService::DISK);
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
            'gcash_number' => '09171112222',
            'gcash_name' => 'Juan Dela Cruz',
        ]);

        return [$profile, $user, $user->createToken('provider', ['client:auth'])->plainTextToken];
    }

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'customer.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    /** A paid job on which the provider owes SkillServe [$fee]. */
    private function owed(ProviderProfile $profile, float $fee = 20): Booking
    {
        $service = Service::create([
            'provider_id' => $profile->id,
            'category_id' => ServiceCategory::create(['name' => 'Aircon '.Str::random(5), 'status' => 'enabled'])->id,
            'title' => 'Aircon Cleaning',
            'description' => 'Split-type aircon deep cleaning.',
            'price' => $fee * 10,
            'price_type' => 'fixed',
            'duration' => '2 hours',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
            'is_hidden' => false,
        ]);

        return Booking::create([
            'service_id' => $service->id,
            'client_id' => $this->customer()->id,
            'provider_id' => $profile->id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)),
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_at' => now(),
            'total_price' => $fee * 10,
            'service_price' => $fee * 10,
            'platform_fee' => $fee,
            'commission_rate' => 10,
            'commission_status' => CommissionLedger::OUTSTANDING,
            'currency' => 'PHP',
            'payment_method' => 'gcash',
            'completed_at' => now(),
        ]);
    }

    /** @param array<int, string> $permissions */
    private function admin(array $permissions): array
    {
        // Policies look up names beyond the ones granted, and Spatie throws if
        // a name does not exist at all; production seeds the whole catalog.
        $catalog = ['view commissions', 'manage commissions', 'settle commissions', 'view support', 'manage support', 'view reports', 'manage reports'];
        foreach ([...$permissions, ...$catalog] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        // The guard is named because an earlier API request in the same test
        // switches the default guard to sanctum.
        $role = Role::findOrCreate('finance-'.Str::random(5), 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return [$user, $user->createToken('admin')->plainTextToken];
    }

    /**
     * Requests as the holder of [$token]. The guard is reset first, because a
     * test that switches between accounts would otherwise keep the first one.
     */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function submit(string $token, array $overrides = [])
    {
        return $this->as($token)->post('/api/client/v1/provider/commission-remittances', array_merge([
            'reference' => '1012 345 678901',
            'amount_sent' => '20.00',
        ], $overrides), ['Accept' => 'application/json']);
    }

    private function receipt(): UploadedFile
    {
        return UploadedFile::fake()->create('receipt.jpg', 120, 'image/jpeg');
    }

    public function test_the_summary_shows_skillserves_gcash_once_it_is_configured(): void
    {
        [, , $token] = $this->provider();

        $this->as($token)->getJson('/api/client/v1/provider/commissions')
            ->assertOk()
            ->assertJsonPath('data.payment_account.configured', false)
            ->assertJsonPath('data.latest_remittance', null);

        Setting::create(['group' => 'commission_payments', 'name' => 'gcash_account_name', 'payload' => 'SkillServe Inc.']);
        Setting::create(['group' => 'commission_payments', 'name' => 'gcash_number', 'payload' => '09171234567']);

        $this->as($token)->getJson('/api/client/v1/provider/commissions')
            ->assertOk()
            ->assertJsonPath('data.payment_account.configured', true)
            ->assertJsonPath('data.payment_account.gcash_account_name', 'SkillServe Inc.')
            ->assertJsonPath('data.payment_account.gcash_number', '09171234567');
    }

    public function test_a_provider_submits_a_payment_that_covers_everything_they_owe(): void
    {
        [$profile, $user, $token] = $this->provider();
        $first = $this->owed($profile, 20);
        $second = $this->owed($profile, 15);

        $this->submit($token, ['amount_sent' => '35', 'proof' => $this->receipt()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reference', '1012345678901')
            ->assertJsonPath('data.amount_due', '35.00')
            ->assertJsonPath('data.amount_sent', '35.00')
            ->assertJsonPath('data.bookings_count', 2)
            ->assertJsonPath('data.has_proof', true);

        $remittance = CommissionRemittance::sole();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $remittance->bookings()->pluck('bookings.id')->all());
        Storage::disk(CommissionRemittanceService::DISK)->assertExists($remittance->proof_path);
        $this->assertTrue(Activity::query()->where('description', 'commission_remittance_submitted')->where('causer_id', $user->id)->exists());

        // Nothing is settled until SkillServe has seen the money arrive.
        $this->assertSame(CommissionLedger::OUTSTANDING, $first->fresh()->commission_status);
        $this->as($token)->getJson('/api/client/v1/provider/commissions')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.latest_remittance.status', 'pending');
    }

    public function test_a_payment_short_of_what_is_owed_is_refused(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile, 20);

        $this->submit($token, ['amount_sent' => '19.99'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_sent');

        $this->assertSame(0, CommissionRemittance::count());
    }

    public function test_a_provider_who_owes_nothing_cannot_submit(): void
    {
        [, , $token] = $this->provider();

        $this->submit($token)->assertStatus(422)->assertJsonValidationErrors('amount_sent');
    }

    public function test_the_reference_and_amount_are_required_and_shaped(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile);

        $this->submit($token, ['reference' => 'ab', 'amount_sent' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference', 'amount_sent']);
    }

    public function test_only_one_payment_waits_for_confirmation_at_a_time(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile);

        $this->submit($token)->assertCreated();
        $this->submit($token, ['reference' => '2000000000001'])->assertStatus(409);
    }

    public function test_a_reference_another_provider_typed_first_does_not_lock_out_the_payer(): void
    {
        [$squatter, , $squatterToken] = $this->provider();
        $this->owed($squatter);
        [$payer, , $payerToken] = $this->provider();
        $this->owed($payer);
        [, $adminToken] = $this->admin(['view commissions', 'settle commissions']);

        $this->submit($squatterToken, ['reference' => '1012345678901'])->assertCreated();

        // Grouped differently, it is still the same receipt — and still accepted.
        $this->submit($payerToken, ['reference' => '1012-345-678 901'])->assertCreated();

        // The reviewer sees that two providers claim one transfer.
        $this->as($adminToken)->getJson('/api/commission-remittances?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.other_claims_count', 1)
            ->assertJsonPath('data.1.other_claims_count', 1);
    }

    public function test_a_reference_pays_only_once(): void
    {
        [$first, , $firstToken] = $this->provider();
        $this->owed($first);
        [$second, , $secondToken] = $this->provider();
        $this->owed($second);
        [, $adminToken] = $this->admin(['view commissions', 'settle commissions']);

        $this->submit($firstToken)->assertCreated();
        $this->submit($secondToken)->assertCreated();
        [$a, $b] = CommissionRemittance::query()->orderBy('id')->pluck('id')->all();

        $this->as($adminToken)->patchJson("/api/commission-remittances/{$a}/confirm")->assertOk();
        $this->as($adminToken)->patchJson("/api/commission-remittances/{$b}/confirm")->assertStatus(409);
        $this->assertSame(CommissionRemittance::PENDING, CommissionRemittance::find($b)->status);

        // Once confirmed, the receipt cannot be submitted again by anyone.
        [$third, , $thirdToken] = $this->provider();
        $this->owed($third);
        $this->submit($thirdToken)->assertStatus(422)->assertJsonValidationErrors('reference');
    }

    public function test_confirming_settles_the_covered_bookings_and_lifts_the_block(): void
    {
        Notification::fake();
        [$profile, $providerUser, $token] = $this->provider();
        $booking = $this->owed($profile, 20);
        $this->submit($token)->assertCreated();
        $remittance = CommissionRemittance::sole();

        // Owed after the payment was sent, so not part of it.
        $later = $this->owed($profile, 5);

        [$admin, $adminToken] = $this->admin(['view commissions', 'settle commissions']);

        $this->as($adminToken)
            ->patchJson("/api/commission-remittances/{$remittance->id}/confirm", ['notes' => 'Seen in GCash history.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.reviewed_by.id', $admin->id)
            ->assertJsonPath('data.bookings.0.commission_status', CommissionLedger::SETTLED);

        $settlement = CommissionSettlement::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame('gcash', $settlement->method);
        $this->assertSame('1012345678901', $settlement->reference);
        $this->assertSame($remittance->id, $settlement->commission_remittance_id);
        $this->assertSame(CommissionLedger::SETTLED, $booking->fresh()->commission_status);
        $this->assertSame(CommissionLedger::OUTSTANDING, $later->fresh()->commission_status);

        // One message about the payment, not one per booking it settled.
        Notification::assertSentTo($providerUser, CommissionRemittanceNotification::class,
            fn ($notification) => $notification->toArray($providerUser)['action'] === 'confirmed');
        Notification::assertNotSentTo($providerUser, CommissionSettlementNotification::class);

        $this->assertTrue(Activity::query()->where('description', 'commission_remittance_confirmed')->where('causer_id', $admin->id)->exists());
    }

    public function test_confirming_clears_the_debt_so_the_provider_can_work_again(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile, 20);
        $this->submit($token)->assertCreated();
        [, $adminToken] = $this->admin(['view commissions', 'settle commissions']);

        $this->as($adminToken)
            ->patchJson('/api/commission-remittances/'.CommissionRemittance::sole()->id.'/confirm')
            ->assertOk();

        $this->as($token)->getJson('/api/client/v1/provider/commissions')
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.outstanding_count', 0)
            ->assertJsonPath('data.latest_remittance.status', 'confirmed');

        // The feed carries what the app needs to open the Commission screen.
        $this->as($token)->getJson('/api/client/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'commission_remittance')
            ->assertJsonPath('data.0.data.action', 'confirmed')
            ->assertJsonPath('data.0.data.remittance_id', CommissionRemittance::sole()->id);
    }

    public function test_rejecting_keeps_the_debt_and_tells_the_provider_why(): void
    {
        Notification::fake();
        [$profile, $providerUser, $token] = $this->provider();
        $booking = $this->owed($profile);
        $this->submit($token)->assertCreated();
        $remittance = CommissionRemittance::sole();
        [, $adminToken] = $this->admin(['view commissions', 'settle commissions']);

        $this->as($adminToken)
            ->patchJson("/api/commission-remittances/{$remittance->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->as($adminToken)
            ->patchJson("/api/commission-remittances/{$remittance->id}/reject", ['reason' => 'Reference not found in our GCash history.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(CommissionLedger::OUTSTANDING, $booking->fresh()->commission_status);
        Notification::assertSentTo($providerUser, CommissionRemittanceNotification::class,
            fn ($notification) => str_contains($notification->toArray($providerUser)['message'], 'Reference not found'));

        $this->as($token)->getJson('/api/client/v1/provider/commissions')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.latest_remittance.status', 'rejected')
            ->assertJsonPath('data.latest_remittance.rejection_reason', 'Reference not found in our GCash history.');

        // A rejected claim frees its reference and lets the provider try again.
        $this->submit($token)->assertCreated();
    }

    public function test_a_payment_is_decided_only_once(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $this->submit($token)->assertCreated();
        $id = CommissionRemittance::sole()->id;
        [, $adminToken] = $this->admin(['view commissions', 'settle commissions']);

        $this->as($adminToken)->patchJson("/api/commission-remittances/{$id}/confirm")->assertOk();
        $this->as($adminToken)->patchJson("/api/commission-remittances/{$id}/confirm")->assertStatus(409);
        $this->as($adminToken)->patchJson("/api/commission-remittances/{$id}/reject", ['reason' => 'Too late.'])->assertStatus(409);
    }

    public function test_reading_and_deciding_need_their_permissions(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $this->submit($token)->assertCreated();
        $id = CommissionRemittance::sole()->id;

        [, $readerToken] = $this->admin(['view commissions']);
        [, $nobodyToken] = $this->admin([]);

        $this->as($nobodyToken)->getJson('/api/commission-remittances')->assertForbidden();
        $this->as($readerToken)->getJson('/api/commission-remittances')->assertOk();
        $this->as($readerToken)->patchJson("/api/commission-remittances/{$id}/confirm")->assertForbidden();
        $this->as($readerToken)->patchJson("/api/commission-remittances/{$id}/reject", ['reason' => 'No.'])->assertForbidden();

        // Neither a provider nor an anonymous caller reaches the review queue.
        $this->as($token)->getJson('/api/commission-remittances')->assertForbidden();
        $this->assertSame(CommissionRemittance::PENDING, CommissionRemittance::sole()->status);
    }

    public function test_only_providers_can_submit_a_payment(): void
    {
        $customer = $this->customer();
        $token = $customer->createToken('client', ['client:auth'])->plainTextToken;

        $this->submit($token)->assertForbidden();
    }

    public function test_the_review_queue_lists_what_to_match_and_filters_by_status(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile, 20);
        $this->submit($token, ['proof' => $this->receipt()])->assertCreated();
        [, $adminToken] = $this->admin(['view commissions']);

        $this->as($adminToken)->getJson('/api/commission-remittances?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', '1012345678901')
            ->assertJsonPath('data.0.amount_due', '20.00')
            ->assertJsonPath('data.0.provider.gcash_number', '09171112222')
            ->assertJsonPath('data.0.bookings.0.commission_amount', '20.00')
            ->assertJsonPath('meta.pagination.total', 1);

        $this->as($adminToken)->getJson('/api/commission-remittances?status=confirmed')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->as($adminToken)->getJson('/api/commission-remittances?search=juan')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_receipt_downloads_only_for_reviewers(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $this->submit($token, ['proof' => $this->receipt()])->assertCreated();
        $id = CommissionRemittance::sole()->id;
        [, $readerToken] = $this->admin(['view commissions']);
        [, $nobodyToken] = $this->admin([]);

        $this->as($readerToken)->get("/api/commission-remittances/{$id}/proof")->assertOk();
        $this->as($nobodyToken)->getJson("/api/commission-remittances/{$id}/proof")->assertForbidden();

        $this->assertSame(1, Activity::query()->where('description', 'commission_remittance_receipt_opened')->count());
    }

    public function test_receipts_are_deleted_after_the_retention_period_but_the_payment_is_kept(): void
    {
        Setting::create(['group' => 'commission_payments', 'name' => 'receipt_retention_days', 'payload' => 30]);
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $this->submit($token, ['proof' => $this->receipt()])->assertCreated();
        $remittance = CommissionRemittance::sole();
        $path = $remittance->proof_path;
        [, $adminToken] = $this->admin(['view commissions', 'settle commissions']);
        $this->as($adminToken)->patchJson("/api/commission-remittances/{$remittance->id}/confirm")->assertOk();

        // Still inside the retention period: kept.
        $this->artisan('commissions:purge-receipts')->assertExitCode(0);
        Storage::disk(CommissionRemittanceService::DISK)->assertExists($path);

        $remittance->refresh()->update(['reviewed_at' => now()->subDays(31)]);
        $this->artisan('commissions:purge-receipts')->assertExitCode(0);

        Storage::disk(CommissionRemittanceService::DISK)->assertMissing($path);
        $remittance->refresh();
        $this->assertNull($remittance->proof_path);
        $this->assertSame(CommissionRemittance::CONFIRMED, $remittance->status);
        $this->assertSame('1012345678901', $remittance->reference);
    }

    public function test_a_waiting_payment_keeps_its_receipt(): void
    {
        Setting::create(['group' => 'commission_payments', 'name' => 'receipt_retention_days', 'payload' => 1]);
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $this->submit($token, ['proof' => $this->receipt()])->assertCreated();
        CommissionRemittance::sole()->forceFill(['created_at' => now()->subYear()])->save();

        $this->artisan('commissions:purge-receipts')->assertExitCode(0);

        Storage::disk(CommissionRemittanceService::DISK)->assertExists(CommissionRemittance::sole()->proof_path);
    }

    public function test_a_payment_without_a_receipt_has_nothing_to_download(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $this->submit($token)->assertCreated()->assertJsonPath('data.has_proof', false);
        [, $readerToken] = $this->admin(['view commissions']);

        $this->as($readerToken)
            ->getJson('/api/commission-remittances/'.CommissionRemittance::sole()->id.'/proof')
            ->assertNotFound();
    }

    public function test_the_provider_sees_their_payment_history(): void
    {
        [$profile, , $token] = $this->provider();
        [, , $otherToken] = $this->provider();
        $this->owed($profile);
        $this->submit($token)->assertCreated();

        $this->as($token)->getJson('/api/client/v1/provider/commission-remittances')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonMissingPath('data.0.provider');

        $this->as($otherToken)->getJson('/api/client/v1/provider/commission-remittances')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->as($token)->getJson('/api/client/v1/provider/commission-remittances?per_page=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_the_sidebar_counts_payments_waiting_for_confirmation(): void
    {
        [$profile, , $token] = $this->provider();
        $this->owed($profile);
        $this->submit($token)->assertCreated();
        [, $readerToken] = $this->admin(['view commissions']);
        [, $nobodyToken] = $this->admin([]);

        $this->as($readerToken)->getJson('/api/dashboard/attention')
            ->assertOk()
            ->assertJsonPath('data.pending_commission_remittances', 1);

        $this->as($nobodyToken)->getJson('/api/dashboard/attention')
            ->assertOk()
            ->assertJsonPath('data.pending_commission_remittances', null);
    }

    public function test_skillserves_gcash_number_must_be_a_philippine_mobile_number(): void
    {
        [, $adminToken] = $this->admin(['manage settings']);

        $this->as($adminToken)
            ->putJson('/api/settings', ['commission_payments' => ['gcash_number' => '12345']])
            ->assertStatus(422);

        $this->as($adminToken)
            ->putJson('/api/settings', ['commission_payments' => ['gcash_number' => '09171234567', 'gcash_account_name' => 'SkillServe Inc.']])
            ->assertOk();
    }
}
