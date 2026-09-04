<?php

namespace App\Modules\ReportsAndModeration\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Reviews\Models\Review;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReportsAndModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createPermissions(array $names): void
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name);
        }
    }

    /**
     * Create an actor with the given permissions and return its Sanctum token.
     *
     * The "manage X" master permissions referenced by every module policy are
     * created too, so hasPermissionTo() never hits PermissionDoesNotExist
     * (they exist in the seeded production DB).
     */
    private function actingModerator(array $permissions): array
    {
        $this->createPermissions(array_merge([
            'manage reports',
            'manage users',
            'manage services',
            'manage reviews',
        ], $permissions));

        $role = Role::findOrCreate('moderator');
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'email' => 'moderator@skillserve.test',
            'password' => Hash::make('password123'),
            'name' => 'Moderator',
            'status' => 'active',
        ]);
        $user->assignRole('moderator');

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function createCustomer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('password123'),
            'first_name' => 'Alice',
            'last_name' => 'Customer',
            'name' => 'Alice Customer',
            'status' => 'active',
            'user_type' => 'customer',
        ], $attributes));
    }

    private function createService(): Service
    {
        $providerUser = $this->createCustomer([
            'user_type' => 'provider',
            'first_name' => 'Bob',
            'last_name' => 'Provider',
            'name' => 'Bob Provider',
        ]);

        $provider = ProviderProfile::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Test Provider Co.',
            'verification_status' => 'verified',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Test Category '.uniqid(),
            'status' => 'enabled',
        ]);

        return Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Service',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
    }

    private function createReview(): Review
    {
        $service = $this->createService();
        $client = $this->createCustomer();

        $booking = Booking::create([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $service->provider_id,
            'booking_number' => 'BK-TEST-'.uniqid(),
            'status' => 'completed',
            'payment_status' => 'paid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'USD',
            'completed_at' => now(),
        ]);

        return Review::create([
            'booking_id' => $booking->id,
            'reviewer_id' => $client->id,
            'provider_id' => $service->provider_id,
            'service_id' => $service->id,
            'rating' => 2,
            'comment' => 'Very poor service.',
            'status' => 'active',
        ]);
    }

    private function createMessage(): Message
    {
        return Message::create([
            'sender_id' => $this->createCustomer()->id,
            'receiver_id' => $this->createCustomer()->id,
            'content' => 'Suspicious message content.',
            'status' => 'active',
        ]);
    }

    private function createReport(mixed $target, array $attributes = []): Report
    {
        return Report::create(array_merge([
            'reportable_type' => $target->getMorphClass(),
            'reportable_id' => $target->id,
            'reporter_id' => $this->createCustomer()->id,
            'reason' => 'fraud',
            'description' => 'Reported for review.',
            'status' => 'pending',
        ], $attributes));
    }

    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson('/api/reports')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $this->createPermissions(['view reports', 'manage reports']);

        $user = $this->createCustomer();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/reports')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_index_returns_paginated_envelope_with_search_filter_and_sort(): void
    {
        [, $token] = $this->actingModerator(['view reports']);

        $customer = $this->createCustomer([
            'first_name' => 'Zoe',
            'last_name' => 'Search',
            'name' => 'Zoe Search',
        ]);
        $service = $this->createService();

        $this->createReport($customer, ['reason' => 'harassment', 'status' => 'pending', 'description' => 'Sent repeated abusive messages.']);
        $this->createReport($service, ['reason' => 'misleading', 'status' => 'investigating']);
        $this->createReport($customer, ['reason' => 'spam', 'status' => 'resolved']);

        $this->withToken($token)
            ->getJson('/api/reports?search=abusive&status=pending&sort=created_at&direction=desc&per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.reason', 'harassment')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'type', 'reason', 'status', 'reporter', 'reportable', 'created_at', 'updated_at',
                    ],
                ],
                'meta' => ['pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to']],
            ]);

        // Type filter.
        $this->withToken($token)
            ->getJson('/api/reports?type=service')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.type', 'service');

        // Status filter.
        $this->withToken($token)
            ->getJson('/api/reports?status=resolved')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.status', 'resolved');

        // Search by report ID.
        $report = Report::query()->where('reason', 'harassment')->firstOrFail();
        $this->withToken($token)
            ->getJson("/api/reports?search={$report->id}")
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $report->id);
    }

    public function test_show_returns_a_single_report_with_its_reported_item(): void
    {
        [, $token] = $this->actingModerator(['view reports']);
        $service = $this->createService();
        $report = $this->createReport($service);

        $this->withToken($token)
            ->getJson("/api/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $report->id)
            ->assertJsonPath('data.type', 'service')
            ->assertJsonPath('data.reportable.title', 'Test Service')
            ->assertJsonPath('data.reportable.provider.business_name', 'Test Provider Co.');
    }

    public function test_investigate_assigns_investigator_and_records_audit(): void
    {
        [$actor, $token] = $this->actingModerator(['view reports', 'investigate reports']);
        $customer = $this->createCustomer();
        $report = $this->createReport($customer);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/investigate", ['note' => 'Looking into this now.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'investigating')
            ->assertJsonPath('data.investigated_by.name', 'Moderator')
            ->assertJsonCount(1, 'data.investigation_notes');

        $this->assertDatabaseHas('activity_log', ['description' => 'report_investigated']);
    }

    public function test_add_note_appends_to_investigation_notes(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'investigate reports']);
        $customer = $this->createCustomer();
        $report = $this->createReport($customer, ['status' => 'investigating']);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/notes", ['note' => 'Evidence reviewed.'])
            ->assertOk()
            ->assertJsonCount(1, 'data.investigation_notes')
            ->assertJsonPath('data.investigation_notes.0.note', 'Evidence reviewed.');
    }

    public function test_add_note_requires_a_note(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'investigate reports']);
        $report = $this->createReport($this->createCustomer());

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/notes", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['note']]);
    }

    public function test_resolve_marks_report_resolved_and_records_audit(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'resolve reports']);
        $report = $this->createReport($this->createCustomer(), ['status' => 'investigating']);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/resolve", ['resolution_note' => 'Warning issued.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution_note', 'Warning issued.');

        $this->assertDatabaseHas('activity_log', ['description' => 'report_resolved']);
    }

    public function test_reject_marks_report_rejected(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'resolve reports']);
        $report = $this->createReport($this->createCustomer(), ['status' => 'investigating']);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/reject", ['reason' => 'No violation found.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason', 'No violation found.');

        $this->assertDatabaseHas('activity_log', ['description' => 'report_rejected']);
    }

    public function test_terminal_reports_reject_further_lifecycle_changes(): void
    {
        [, $token] = $this->actingModerator([
            'view reports', 'investigate reports', 'resolve reports',
        ]);
        $report = $this->createReport($this->createCustomer(), ['status' => 'resolved']);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/investigate", [])
            ->assertStatus(422);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/notes", ['note' => 'Too late.'])
            ->assertStatus(422);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/resolve", ['resolution_note' => 'Again.'])
            ->assertStatus(422);
    }

    public function test_suspend_action_suspends_the_reported_user(): void
    {
        [$actor, $token] = $this->actingModerator(['view reports', 'manage moderation', 'suspend users']);
        $customer = $this->createCustomer(['email' => 'suspend.report@skillserve.test']);
        $report = $this->createReport($customer);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", [
                'action' => 'suspend',
                'reason' => 'Repeated policy violations.',
            ])
            ->assertOk()
            ->assertJsonPath('data.moderation_action', 'suspend')
            ->assertJsonPath('data.reportable.status', 'suspended');

        $this->assertSame('suspended', $customer->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['description' => 'report_action_taken']);
    }

    public function test_action_requires_the_underlying_module_permission(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'manage moderation']);
        $customer = $this->createCustomer();
        $report = $this->createReport($customer);

        // The actor has "manage moderation" but not "suspend users" —
        // the reports module must not bypass User Management authorization.
        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", [
                'action' => 'suspend',
                'reason' => 'Suspended via reports.',
            ])
            ->assertStatus(403);

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_hide_action_hides_a_reported_review(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'manage moderation', 'edit reviews']);
        $review = $this->createReview();
        $report = $this->createReport($review);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", ['action' => 'hide'])
            ->assertOk()
            ->assertJsonPath('data.moderation_action', 'hide');

        $this->assertSame('hidden', $review->fresh()->status);
    }

    public function test_remove_action_removes_a_reported_message(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'manage moderation']);
        $message = $this->createMessage();
        $report = $this->createReport($message);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", ['action' => 'remove'])
            ->assertOk()
            ->assertJsonPath('data.moderation_action', 'remove');

        $this->assertSame('removed', $message->fresh()->status);
    }

    public function test_action_not_applicable_to_the_reported_type_returns_422(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'manage moderation', 'edit reviews']);
        $customer = $this->createCustomer();
        $report = $this->createReport($customer);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", ['action' => 'hide'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['action']]);
    }

    public function test_ban_action_records_ban_on_the_reported_user(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'manage moderation', 'ban users']);
        $customer = $this->createCustomer(['email' => 'ban.report@skillserve.test']);
        $report = $this->createReport($customer);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", [
                'action' => 'ban',
                'reason' => 'Serious violations.',
                'duration' => 'forever',
            ])
            ->assertOk()
            ->assertJsonPath('data.moderation_action', 'ban');

        $this->assertSame('banned', $customer->fresh()->status);
    }

    public function test_ban_action_with_days_sets_a_banned_until_date(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'manage moderation', 'ban users']);
        $customer = $this->createCustomer(['email' => 'temp.ban.report@skillserve.test']);
        $report = $this->createReport($customer);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", [
                'action' => 'ban',
                'reason' => 'Cooling-off period.',
                'duration' => 'days',
                'days' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('data.moderation_action', 'ban');

        $this->assertSame('banned', $customer->fresh()->status);
        $this->assertNotNull($customer->fresh()->banned_until);
    }

    public function test_suspend_action_requires_a_reason(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'manage moderation', 'suspend users']);
        $report = $this->createReport($this->createCustomer());

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", ['action' => 'suspend'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_hide_action_hides_a_reported_service(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'manage moderation', 'edit services']);
        $service = $this->createService();
        $report = $this->createReport($service);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", ['action' => 'hide'])
            ->assertOk()
            ->assertJsonPath('data.moderation_action', 'hide');

        $this->assertTrue($service->fresh()->is_hidden);
    }

    public function test_view_reports_alone_does_not_grant_investigate(): void
    {
        [, $token] = $this->actingModerator(['view reports']);
        $report = $this->createReport($this->createCustomer());

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/investigate", [])
            ->assertStatus(403);
    }

    public function test_terminal_reports_cannot_be_rejected_or_actioned(): void
    {
        [, $token] = $this->actingModerator([
            'view reports', 'resolve reports', 'manage moderation', 'suspend users',
        ]);
        $report = $this->createReport($this->createCustomer(), ['status' => 'resolved']);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/reject", ['reason' => 'Too late.'])
            ->assertStatus(422);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", ['action' => 'suspend', 'reason' => 'Too late.'])
            ->assertStatus(422);
    }

    public function test_action_on_a_missing_reportable_returns_422(): void
    {
        [, $token] = $this->actingModerator(['view reports', 'manage moderation', 'suspend users']);
        $customer = $this->createCustomer();
        $report = $this->createReport($customer);
        $customer->delete();

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", ['action' => 'suspend', 'reason' => 'Gone.'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reportable']]);
    }
}
