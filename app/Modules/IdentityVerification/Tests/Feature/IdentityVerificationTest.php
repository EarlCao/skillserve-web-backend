<?php

namespace App\Modules\IdentityVerification\Tests\Feature;

use App\Models\User;
use App\Modules\DataManagement\Services\DataManagementService;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\IdentityVerification\Models\IdentityVerificationEvent;
use App\Modules\IdentityVerification\Services\NationalIdHasher;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * National ID verification is shared by customers and providers, and is
 * separate from provider business verification.
 */
class IdentityVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const ID_A = '1234567890123456';

    private const ID_B = '6543210987654321';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('identity');
    }

    /** @return array{0: User, 1: string} */
    private function account(string $type = 'customer'): array
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

        return [$user, $user->createToken('client', ['client:auth'])->plainTextToken];
    }

    /**
     * `create()` rather than `image()`: generating a real image needs the GD
     * extension, which is not present in every environment the suite runs in.
     *
     * @return array<string, mixed>
     */
    private function payload(string $idNumber = self::ID_A): array
    {
        return [
            'id_number' => $idNumber,
            'full_name' => 'Juan Dela Cruz',
            'birthdate' => '1995-04-02',
            'documents' => [
                ['type' => 'id_front', 'file' => UploadedFile::fake()->create('front.jpg', 120, 'image/jpeg')],
                ['type' => 'id_back', 'file' => UploadedFile::fake()->create('back.jpg', 120, 'image/jpeg')],
            ],
        ];
    }

    public function test_the_endpoint_is_closed_to_anonymous_callers(): void
    {
        $this->getJson('/api/client/v1/identity-verification')->assertStatus(401);
        $this->postJson('/api/client/v1/identity-verification', [])->assertStatus(401);
    }

    public function test_a_new_account_starts_unverified(): void
    {
        [, $token] = $this->account();

        $this->withToken($token)
            ->getJson('/api/client/v1/identity-verification')
            ->assertOk()
            ->assertJsonPath('data.status', 'unverified')
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.id_number_last4', null);
    }

    public function test_a_customer_submits_their_national_id(): void
    {
        [$user, $token] = $this->account('customer');

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can_submit', false)
            ->assertJsonPath('data.id_number_last4', '3456');

        $record = IdentityVerification::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('pending', $record->status);
        $this->assertSame(app(NationalIdHasher::class)->hash(self::ID_A), $record->id_number_hash);
        $this->assertCount(2, $record->documents);

        foreach ($record->documents as $document) {
            Storage::disk('identity')->assertExists($document->file_path);
        }
    }

    public function test_a_provider_uses_the_same_endpoint(): void
    {
        [, $token] = $this->account('provider');

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_the_number_is_never_returned_or_serialised(): void
    {
        [$user, $token] = $this->account();

        $response = $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload());

        $body = $response->getContent();

        $this->assertStringNotContainsString(self::ID_A, $body);
        $this->assertStringNotContainsString('id_number_hash', $body);
        $this->assertStringNotContainsString('file_path', $body);

        // Even a blunt toArray() must not leak the number or the hash.
        $record = IdentityVerification::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertArrayNotHasKey('id_number_hash', $record->toArray());
        $this->assertArrayNotHasKey('id_number_encrypted', $record->toArray());
    }

    public function test_formatting_does_not_create_a_second_identity(): void
    {
        $hasher = app(NationalIdHasher::class);

        $this->assertSame($hasher->hash('1234-5678-9012-3456'), $hasher->hash('1234567890123456'));
        $this->assertSame($hasher->hash('1234 5678 9012 3456'), $hasher->hash('1234567890123456'));
    }

    public function test_one_national_id_cannot_back_two_accounts(): void
    {
        [, $firstToken] = $this->account();
        [, $secondToken] = $this->account();

        $this->withToken($firstToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload(self::ID_A))
            ->assertStatus(201);

        $this->app['auth']->forgetGuards();

        // Same card, written differently — must still be refused.
        $this->withToken($secondToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload('1234-5678-9012-3456'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_number');

        $this->assertSame(1, IdentityVerification::query()->whereNotNull('id_number_hash')->count());
    }

    public function test_the_refusal_does_not_reveal_which_account_holds_the_id(): void
    {
        [$owner, $firstToken] = $this->account();
        [, $secondToken] = $this->account();

        $this->withToken($firstToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        $this->app['auth']->forgetGuards();

        $response = $this->withToken($secondToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload());

        $response->assertStatus(422);
        $this->assertStringNotContainsString($owner->email, $response->getContent());
        $this->assertStringNotContainsString((string) $owner->id, $response->json('errors.id_number.0'));
    }

    public function test_a_different_id_is_accepted_on_a_second_account(): void
    {
        [, $firstToken] = $this->account();
        [, $secondToken] = $this->account();

        $this->withToken($firstToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload(self::ID_A))
            ->assertStatus(201);

        $this->app['auth']->forgetGuards();

        $this->withToken($secondToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload(self::ID_B))
            ->assertStatus(201);
    }

    public function test_submitting_while_pending_or_verified_is_refused(): void
    {
        [$user, $token] = $this->account();

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(422);

        IdentityVerification::query()->where('user_id', $user->id)
            ->update(['status' => IdentityVerification::VERIFIED]);

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(422);
    }

    public function test_a_rejected_account_can_resubmit_the_same_id(): void
    {
        [$user, $token] = $this->account();

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        IdentityVerification::query()->where('user_id', $user->id)->update([
            'status' => IdentityVerification::REJECTED,
            'rejection_reason' => 'The back of the card was unreadable.',
        ]);

        // Their own ID must not collide with their own previous submission.
        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.rejection_reason', null);
    }

    public function test_a_resubmission_replaces_the_previous_images(): void
    {
        [$user, $token] = $this->account();

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        $record = IdentityVerification::query()->where('user_id', $user->id)->firstOrFail();
        $oldPaths = $record->documents->pluck('file_path')->all();

        IdentityVerification::query()->whereKey($record->id)
            ->update(['status' => IdentityVerification::REJECTED]);

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        foreach ($oldPaths as $path) {
            Storage::disk('identity')->assertMissing($path);
        }

        $this->assertCount(2, $record->fresh()->documents);
    }

    public function test_the_number_must_be_sixteen_digits(): void
    {
        [, $token] = $this->account();

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload('12345'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_number');
    }

    public function test_documents_are_required_and_type_checked(): void
    {
        [, $token] = $this->account();

        $payload = $this->payload();
        unset($payload['documents']);

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('documents');

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', [
                ...$this->payload(),
                'documents' => [
                    ['type' => 'id_front', 'file' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain')],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('documents.0.file');
    }

    public function test_a_failed_submission_leaves_no_files_behind(): void
    {
        [, $firstToken] = $this->account();
        [, $secondToken] = $this->account();

        $this->withToken($firstToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        $this->app['auth']->forgetGuards();

        $before = count(Storage::disk('identity')->allFiles());

        $this->withToken($secondToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(422);

        $this->assertCount($before, Storage::disk('identity')->allFiles());
    }

    public function test_every_submission_is_recorded_in_the_history(): void
    {
        [$user, $token] = $this->account();

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        $record = IdentityVerification::query()->where('user_id', $user->id)->firstOrFail();
        $event = $record->events()->first();

        $this->assertNotNull($event);
        $this->assertSame(IdentityVerificationEvent::SUBMITTED, $event->action);
        $this->assertSame($user->id, $event->actor_id);
    }

    public function test_soft_deleting_an_account_does_not_free_its_national_id(): void
    {
        [$user, $token] = $this->account();
        [, $otherToken] = $this->account();

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        // An administrator can restore this account for 30 days, so the ID
        // must stay claimed until deletion is permanent.
        $user->delete();

        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(422);
    }

    public function test_permanently_deleting_an_account_frees_its_national_id(): void
    {
        [$user, $token] = $this->account();
        [, $otherToken] = $this->account();

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        $record = IdentityVerification::query()->where('user_id', $user->id)->firstOrFail();

        $user->delete();
        $admin = User::factory()->create(['status' => 'active']);
        app(DataManagementService::class)->permanentlyDelete('users', $user->id, $admin);

        $record->refresh();

        $this->assertNotNull($record->released_at);
        // The audit trail survives the account; the number itself does not.
        $this->assertNull($record->user_id);
        $this->assertNull($record->id_number_encrypted);
        $this->assertNotNull($record->id_number_hash);
        $this->assertCount(0, $record->documents()->get());

        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);
    }

    public function test_retention_purges_the_images_but_keeps_the_decision(): void
    {
        [$user, $token] = $this->account();

        $this->withToken($token)
            ->postJson('/api/client/v1/identity-verification', $this->payload())
            ->assertStatus(201);

        $record = IdentityVerification::query()->where('user_id', $user->id)->firstOrFail();
        $paths = $record->documents->pluck('file_path')->all();

        $record->update([
            'status' => IdentityVerification::VERIFIED,
            'documents_purge_after' => now()->subDay(),
        ]);

        $this->artisan('identity:purge-documents')->assertExitCode(0);

        foreach ($paths as $path) {
            Storage::disk('identity')->assertMissing($path);
        }

        $record->refresh();

        $this->assertSame(IdentityVerification::VERIFIED, $record->status);
        $this->assertCount(0, $record->documents()->get());
        $this->assertSame(1, $record->events()->count());
    }
}
