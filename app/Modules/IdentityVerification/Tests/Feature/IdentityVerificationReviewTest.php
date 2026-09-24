<?php

namespace App\Modules\IdentityVerification\Tests\Feature;

use App\Models\User;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\IdentityVerification\Models\IdentityVerificationEvent;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The administrator side of National ID verification: the review queue, the
 * two decisions, and the authorised document download.
 */
class IdentityVerificationReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('identity');
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: User, 1: string}
     */
    private function reviewer(array $permissions): array
    {
        // The guard is named explicitly: the client requests these tests make
        // first leave Sanctum as the default guard, so a role created without
        // it lands on the wrong guard and assignRole() cannot find it.
        foreach ([...$permissions, 'view identity verifications', 'verify identities', 'reject identities'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::findOrCreate('reviewer-'.Str::random(5), 'web');
        $role->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return [$user, $user->createToken('admin')->plainTextToken];
    }

    /** An account with a pending National ID submission. */
    private function submission(string $type = 'customer'): IdentityVerification
    {
        $user = User::factory()->create([
            'email' => $type.'.'.Str::random(8).'@skillserve.test',
            'user_type' => $type,
            'status' => 'active',
        ]);

        if ($type === 'provider') {
            ProviderProfile::create([
                'user_id' => $user->id,
                'business_name' => 'Juan Aircon Services',
                'verification_status' => 'verified',
            ]);
        }

        $token = $user->createToken('client', ['client:auth'])->plainTextToken;

        $this->withToken($token)->postJson('/api/client/v1/identity-verification', [
            'id_number' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'full_name' => 'Juan Dela Cruz',
            'birthdate' => '1995-04-02',
            'documents' => [
                ['type' => 'id_front', 'file' => UploadedFile::fake()->create('front.jpg', 120, 'image/jpeg')],
            ],
        ])->assertStatus(201);

        $this->app['auth']->forgetGuards();

        return IdentityVerification::query()->where('user_id', $user->id)->firstOrFail();
    }

    public function test_the_queue_is_closed_without_permission(): void
    {
        $this->getJson('/api/identity-verifications')->assertStatus(401);

        [, $token] = $this->reviewer(['view bookings']);

        $this->withToken($token)->getJson('/api/identity-verifications')->assertStatus(403);
    }

    public function test_a_reviewer_lists_and_filters_submissions(): void
    {
        $this->submission('customer');
        $this->submission('provider');

        [, $token] = $this->reviewer(['view identity verifications']);

        $this->withToken($token)->getJson('/api/identity-verifications')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withToken($token)->getJson('/api/identity-verifications?account_type=provider')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account.account_type', 'provider');

        $this->withToken($token)->getJson('/api/identity-verifications?status=pending')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_card_number_is_never_returned_to_the_reviewer(): void
    {
        $record = $this->submission();
        [, $token] = $this->reviewer(['view identity verifications']);

        $list = $this->withToken($token)->getJson('/api/identity-verifications');
        $detail = $this->withToken($token)->getJson("/api/identity-verifications/{$record->id}");

        foreach ([$list, $detail] as $response) {
            $response->assertOk();
            $body = $response->getContent();

            $this->assertStringNotContainsString('id_number_hash', $body);
            $this->assertStringNotContainsString('id_number_encrypted', $body);
            $this->assertStringNotContainsString('file_path', $body);
        }

        // Only the last four digits tie a row to a submission.
        $detail->assertJsonPath('data.id_number_last4', $record->id_number_last4);
    }

    public function test_approving_verifies_the_identity_and_starts_the_retention_clock(): void
    {
        $record = $this->submission();
        [$actor, $token] = $this->reviewer(['view identity verifications', 'verify identities']);

        Setting::updateOrCreate(
            ['group' => 'identity', 'name' => 'identity_document_retention_days'],
            ['payload' => 30],
        );

        $this->withToken($token)
            ->patchJson("/api/identity-verifications/{$record->id}/approve", ['notes' => 'Matches the selfie.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $record->refresh();

        $this->assertSame(IdentityVerification::VERIFIED, $record->status);
        $this->assertSame($actor->id, $record->reviewed_by);
        $this->assertNotNull($record->reviewed_at);
        // The images are only needed while under review or dispute.
        $this->assertTrue($record->documents_purge_after->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_rejecting_requires_a_reason_and_tells_the_holder(): void
    {
        $record = $this->submission();
        [, $token] = $this->reviewer(['view identity verifications', 'reject identities']);

        $this->withToken($token)
            ->patchJson("/api/identity-verifications/{$record->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->withToken($token)
            ->patchJson("/api/identity-verifications/{$record->id}/reject", [
                'reason' => 'The back of the card was unreadable.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'The back of the card was unreadable.');

        // The holder sees the reason and can submit again.
        $this->app['auth']->forgetGuards();
        $holderToken = $record->user->createToken('client', ['client:auth'])->plainTextToken;

        $this->withToken($holderToken)
            ->getJson('/api/client/v1/identity-verification')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.rejection_reason', 'The back of the card was unreadable.');
    }

    public function test_approving_and_rejecting_are_separate_permissions(): void
    {
        $record = $this->submission();
        [, $readerToken] = $this->reviewer(['view identity verifications']);

        $this->withToken($readerToken)
            ->patchJson("/api/identity-verifications/{$record->id}/approve")
            ->assertStatus(403);

        $this->withToken($readerToken)
            ->patchJson("/api/identity-verifications/{$record->id}/reject", ['reason' => 'No.'])
            ->assertStatus(403);

        // Approving must not imply the ability to reject.
        [, $approverToken] = $this->reviewer(['view identity verifications', 'verify identities']);

        $this->withToken($approverToken)
            ->patchJson("/api/identity-verifications/{$record->id}/reject", ['reason' => 'No.'])
            ->assertStatus(403);
    }

    public function test_a_submission_cannot_be_decided_twice(): void
    {
        $record = $this->submission();
        [, $token] = $this->reviewer(['view identity verifications', 'verify identities', 'reject identities']);

        $this->withToken($token)
            ->patchJson("/api/identity-verifications/{$record->id}/approve")
            ->assertOk();

        $this->withToken($token)
            ->patchJson("/api/identity-verifications/{$record->id}/approve")
            ->assertStatus(409);

        $this->withToken($token)
            ->patchJson("/api/identity-verifications/{$record->id}/reject", ['reason' => 'Changed my mind.'])
            ->assertStatus(409);
    }

    public function test_decisions_are_recorded_in_the_history_and_the_audit_log(): void
    {
        $record = $this->submission();
        [$actor, $token] = $this->reviewer(['view identity verifications', 'reject identities']);

        $this->withToken($token)
            ->patchJson("/api/identity-verifications/{$record->id}/reject", ['reason' => 'Blurred photo.'])
            ->assertOk()
            ->assertJsonPath('data.history.1.action', IdentityVerificationEvent::REJECTED)
            ->assertJsonPath('data.history.1.reason', 'Blurred photo.');

        $activity = Activity::query()
            ->where('description', 'identity_verification_rejected')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
        // The audit log is widely readable: last4 only, never the number.
        $this->assertSame($record->id_number_last4, $activity->properties['id_number_last4']);
        $this->assertArrayNotHasKey('id_number', $activity->properties->toArray());
    }

    public function test_a_document_can_be_opened_only_through_its_own_submission(): void
    {
        $first = $this->submission();
        $second = $this->submission();
        [, $token] = $this->reviewer(['view identity verifications']);

        $document = $first->documents()->firstOrFail();

        $this->withToken($token)
            ->get("/api/identity-verifications/{$first->id}/documents/{$document->id}/download")
            ->assertOk();

        // Walking another person's ID by swapping the submission id must 404.
        $this->withToken($token)
            ->get("/api/identity-verifications/{$second->id}/documents/{$document->id}/download")
            ->assertStatus(404);
    }

    public function test_opening_a_document_requires_permission_and_is_audited(): void
    {
        $record = $this->submission();
        $document = $record->documents()->firstOrFail();

        [, $strangerToken] = $this->reviewer(['view bookings']);

        $this->withToken($strangerToken)
            ->get("/api/identity-verifications/{$record->id}/documents/{$document->id}/download")
            ->assertStatus(403);

        [$actor, $token] = $this->reviewer(['view identity verifications']);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->get("/api/identity-verifications/{$record->id}/documents/{$document->id}/download")
            ->assertOk();

        $activity = Activity::query()->where('description', 'identity_document_opened')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_a_purged_document_is_gone_rather_than_erroring(): void
    {
        $record = $this->submission();
        $document = $record->documents()->firstOrFail();
        [, $token] = $this->reviewer(['view identity verifications']);

        Storage::disk('identity')->delete($document->file_path);

        $this->withToken($token)
            ->get("/api/identity-verifications/{$record->id}/documents/{$document->id}/download")
            ->assertStatus(404);
    }
}
