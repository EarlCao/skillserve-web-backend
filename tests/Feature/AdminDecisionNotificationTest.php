<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\Bookings\Notifications\DisputeUpdateNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationRequest;
use App\Modules\Providers\Notifications\ProviderAccountNotification;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\ReportsAndModeration\Notifications\ReportOutcomeNotification;
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
 * H1: every administrator decision reaches the people it affects — never the
 * administrator, and never with details the recipient should not see.
 */
class AdminDecisionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();

        $all = [
            'manage providers', 'view providers', 'verify providers', 'reject providers', 'suspend providers', 'activate providers',
            'manage reports', 'view reports', 'resolve reports', 'manage bookings', 'view bookings', 'manage booking disputes',
        ];
        foreach ($all as $name) {
            Permission::findOrCreate($name);
        }
        $role = Role::create(['name' => 'decider']);
        $role->syncPermissions(['manage providers', 'manage reports', 'manage bookings']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);
        $this->token = $admin->createToken('a')->plainTextToken;
    }

    public function test_verification_decisions_reach_the_provider_with_the_reason(): void
    {
        [$profile, $providerUser] = $this->pendingProvider();

        $this->withToken($this->token)
            ->patchJson("/api/providers/{$profile->id}/verification/request-info", ['message' => 'Add the back of your ID.'])
            ->assertOk();
        $this->assertProviderNotified($providerUser, 'info_requested', 'Add the back of your ID.');

        // Answer, then reject.
        VerificationRequest::query()->update(['status' => 'pending']);
        $profile->update(['verification_status' => 'pending']);
        $this->withToken($this->token)
            ->patchJson("/api/providers/{$profile->id}/verification/reject", ['reason' => 'The photo is blurry.'])
            ->assertOk();
        $this->assertProviderNotified($providerUser, 'rejected', 'The photo is blurry.');

        $profile->verificationRequests()->create(['status' => 'pending', 'submitted_at' => now()]);
        $profile->update(['verification_status' => 'pending']);
        $this->withToken($this->token)->patchJson("/api/providers/{$profile->id}/verification/approve")->assertOk();
        $this->assertProviderNotified($providerUser, 'approved');
    }

    public function test_provider_suspension_and_reactivation_reach_the_provider(): void
    {
        [$profile, $providerUser] = $this->pendingProvider(['verification_status' => 'verified']);

        $this->withToken($this->token)
            ->patchJson("/api/providers/{$profile->id}/suspend", ['reason' => 'Complaints under review.'])
            ->assertOk();
        $this->assertProviderNotified($providerUser, 'suspended', 'Complaints under review.');

        $this->withToken($this->token)->patchJson("/api/providers/{$profile->id}/activate")->assertOk();
        $this->assertProviderNotified($providerUser, 'activated');
    }

    public function test_the_reporter_hears_the_outcome_but_not_the_action_taken(): void
    {
        $reporter = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $target = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $report = fn () => Report::create([
            'reportable_type' => $target->getMorphClass(), 'reportable_id' => $target->id,
            'reporter_id' => $reporter->id, 'reason' => 'spam', 'description' => 'Spam messages.', 'status' => 'pending',
        ]);

        $resolved = $report();
        $this->withToken($this->token)
            ->patchJson("/api/reports/{$resolved->id}/resolve", ['resolution_note' => 'User warned.'])
            ->assertOk();
        Notification::assertSentTo($reporter, ReportOutcomeNotification::class, function ($n) use ($reporter, $resolved) {
            $data = $n->toArray($reporter);

            return $data['action'] === 'resolved' && $data['report_id'] === $resolved->id
                && ! str_contains($data['message'], 'User warned.');
        });

        $rejected = $report();
        $this->withToken($this->token)
            ->patchJson("/api/reports/{$rejected->id}/reject", ['reason' => 'No violation found in the messages.'])
            ->assertOk();
        Notification::assertSentTo($reporter, ReportOutcomeNotification::class, fn ($n) => $n->toArray($reporter)['action'] === 'rejected');
        Notification::assertNotSentTo($target, ReportOutcomeNotification::class);
    }

    public function test_dispute_decisions_reach_both_parties_without_a_misleading_job_completed(): void
    {
        $booking = $this->disputedBooking();
        $client = User::query()->findOrFail($booking->client_id);
        $providerUser = User::query()->findOrFail(ProviderProfile::query()->findOrFail($booking->provider_id)->user_id);

        $this->withToken($this->token)->patchJson("/api/disputes/{$booking->id}/investigate")->assertOk();
        $this->withToken($this->token)
            ->patchJson("/api/disputes/{$booking->id}/resolve", ['resolution' => 'Partial refund of 500 agreed.'])
            ->assertOk();

        foreach ([$client, $providerUser] as $party) {
            Notification::assertSentTo($party, DisputeUpdateNotification::class, fn ($n) => $n->toArray($party)['action'] === 'investigating');
            Notification::assertSentTo($party, DisputeUpdateNotification::class, fn ($n) => $n->toArray($party)['action'] === 'resolved'
                && $n->toArray($party)['reason'] === 'Partial refund of 500 agreed.');
            Notification::assertNotSentTo($party, BookingStatusNotification::class);
        }
    }

    private function assertProviderNotified(User $providerUser, string $action, ?string $reason = null): void
    {
        Notification::assertSentTo($providerUser, ProviderAccountNotification::class, function ($n) use ($providerUser, $action, $reason) {
            $data = $n->toArray($providerUser);

            return $data['action'] === $action && ($reason === null || $data['reason'] === $reason);
        });
    }

    /** @return array{0: ProviderProfile, 1: User} */
    private function pendingProvider(array $attributes = []): array
    {
        $user = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $profile = ProviderProfile::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Provider '.Str::random(4),
            'verification_status' => 'pending',
        ], $attributes));
        $profile->verificationRequests()->create(['status' => 'pending', 'submitted_at' => now()]);

        return [$profile, $user];
    }

    private function disputedBooking(): Booking
    {
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        [$profile] = $this->pendingProvider(['verification_status' => 'verified']);
        $category = ServiceCategory::create(['name' => 'Disp '.Str::random(5), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $profile->id, 'category_id' => $category->id, 'title' => 'Aircon Cleaning',
            'price' => 1000, 'status' => 'published', 'approval_status' => 'approved',
        ]);

        return Booking::create([
            'service_id' => $service->id, 'client_id' => $client->id, 'provider_id' => $profile->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(10)), 'status' => 'disputed', 'payment_status' => 'unpaid',
            'total_price' => 1000, 'service_price' => 1000, 'platform_fee' => 100, 'currency' => 'PHP',
            'dispute_reason' => 'Unit still leaking.', 'disputed_at' => now(), 'dispute_status' => 'pending',
        ]);
    }
}
