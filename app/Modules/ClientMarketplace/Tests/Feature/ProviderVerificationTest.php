<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** M 9.3 submit verification, M 9.4 status, M 9.5 respond to an information request. */
class ProviderVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('verification');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_new_provider_submits_documents_and_an_admin_can_open_and_approve_them(): void
    {
        $admin = $this->admin();
        [$profile, $token] = $this->provider();

        $this->withToken($token)->getJson('/api/client/v1/provider/verification')
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'unverified')
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.request', null);

        $this->withToken($token)
            ->post('/api/client/v1/provider/verification', [
                'documents' => [
                    ['type' => 'government_id', 'file' => UploadedFile::fake()->image('id.jpg')],
                    ['type' => 'certificate', 'file' => UploadedFile::fake()->create('tesda.pdf', 200, 'application/pdf')],
                ],
                'notes' => 'TESDA NC II attached.',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.can_submit', false)
            ->assertJsonPath('data.request.status', 'pending')
            ->assertJsonCount(2, 'data.request.documents')
            ->assertJsonMissingPath('data.request.documents.0.file_path');

        $document = VerificationRequest::query()->firstOrFail()->documents()->firstOrFail();
        Storage::disk('verification')->assertExists($document->file_path);
        $this->assertSame('pending', $profile->fresh()->verification_status);
        $this->assertDatabaseHas('activity_log', ['event' => 'provider_verification_submitted']);

        // The admin side sees and opens the uploaded file, then approves.
        $this->app['auth']->forgetGuards();
        $this->withToken($admin)
            ->get("/api/providers/{$profile->id}/verification-documents/{$document->id}/download")
            ->assertOk();
        $this->withToken($admin)
            ->patchJson("/api/providers/{$profile->id}/verification/approve")
            ->assertOk();
        $this->assertSame('verified', $profile->fresh()->verification_status);
    }

    public function test_an_information_request_is_answered_on_the_same_request(): void
    {
        $admin = $this->admin();
        [$profile, $token] = $this->provider();
        $this->submit($token);
        $request = VerificationRequest::query()->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->withToken($admin)
            ->patchJson("/api/providers/{$profile->id}/verification/request-info", ['message' => 'Please add the back of your ID.'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/client/v1/provider/verification')
            ->assertJsonPath('data.verification_status', 'additional_info_required')
            ->assertJsonPath('data.request.additional_info_request', 'Please add the back of your ID.')
            ->assertJsonPath('data.can_submit', true);

        $this->submit($token)
            ->assertCreated()
            ->assertJsonPath('data.request.id', $request->id)
            ->assertJsonPath('data.request.status', 'pending')
            ->assertJsonCount(2, 'data.request.documents');

        $this->assertSame(1, VerificationRequest::query()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'provider_additional_info_submitted']);
    }

    public function test_a_rejected_provider_starts_a_new_request(): void
    {
        $admin = $this->admin();
        [$profile, $token] = $this->provider();
        $this->submit($token);

        $this->app['auth']->forgetGuards();
        $this->withToken($admin)
            ->patchJson("/api/providers/{$profile->id}/verification/reject", ['reason' => 'The ID photo is blurry.'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/client/v1/provider/verification')
            ->assertJsonPath('data.verification_status', 'rejected')
            ->assertJsonPath('data.request.rejection_reason', 'The ID photo is blurry.');

        $this->submit($token)->assertCreated();
        $this->assertSame(2, VerificationRequest::query()->count());
    }

    public function test_submitting_while_pending_or_verified_is_refused(): void
    {
        [, $pendingToken] = $this->provider(['verification_status' => 'pending']);
        $this->submit($pendingToken)->assertStatus(422)->assertJsonPath('message', 'Your documents are already waiting for review.');

        $this->app['auth']->forgetGuards();
        [, $verifiedToken] = $this->provider(['verification_status' => 'verified']);
        $this->submit($verifiedToken)->assertStatus(422)->assertJsonPath('message', 'Your account is already verified.');

        Storage::disk('verification')->assertDirectoryEmpty('/');
    }

    public function test_files_and_types_are_validated(): void
    {
        [, $token] = $this->provider();

        $this->withToken($token)
            ->post('/api/client/v1/provider/verification', [
                'documents' => [
                    ['type' => 'passport_selfie', 'file' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain')],
                ],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents.0.type', 'documents.0.file']);

        $this->withToken($token)
            ->post('/api/client/v1/provider/verification', [
                'documents' => [['type' => 'government_id', 'file' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')]],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('documents.0.file');

        $this->withToken($token)
            ->post('/api/client/v1/provider/verification', [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('documents');
    }

    public function test_customers_cannot_use_the_verification_endpoints(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $this->withToken($customer->createToken('c', ['client:auth'])->plainTextToken)
            ->getJson('/api/client/v1/provider/verification')
            ->assertForbidden();
    }

    private function submit(string $token)
    {
        return $this->withToken($token)->post('/api/client/v1/provider/verification', [
            'documents' => [['type' => 'government_id', 'file' => UploadedFile::fake()->image('id.png')]],
        ], ['Accept' => 'application/json']);
    }

    /** @return array{0: ProviderProfile, 1: string} */
    private function provider(array $attributes = []): array
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(8).'@skillserve.test',
            'user_type' => 'provider',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $profile = ProviderProfile::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Dela Cruz Services',
            'verification_status' => 'unverified',
        ], $attributes));

        return [$profile, $user->createToken('p', ['client:auth'])->plainTextToken];
    }

    private function admin(): string
    {
        foreach (['manage providers', 'view providers', 'verify providers', 'reject providers'] as $name) {
            Permission::findOrCreate($name);
        }
        $role = Role::findOrCreate('provider-reviewer');
        $role->syncPermissions(['manage providers']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('admin')->plainTextToken;
    }
}
