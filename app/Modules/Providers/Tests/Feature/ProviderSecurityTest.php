<?php

namespace App\Modules\Providers\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationDocument;
use App\Modules\Providers\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProviderSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_verification_documents_are_not_exposed_and_download_requires_provider_scope(): void
    {
        Permission::findOrCreate('manage providers');
        Permission::findOrCreate('view providers');
        $role = Role::create(['name' => 'provider-reviewer']);
        $role->givePermissionTo(['manage providers', 'view providers']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);
        $token = $admin->createToken('test')->plainTextToken;

        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Private Documents Provider',
            'verification_status' => 'pending',
            'total_reviews' => 99,
        ]);
        $request = VerificationRequest::create([
            'provider_profile_id' => $provider->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        $document = VerificationDocument::create([
            'verification_request_id' => $request->id,
            'document_type' => 'government_id',
            'file_name' => 'identity.pdf',
            'file_path' => "verification/{$provider->id}/identity.pdf",
            'file_mime_type' => 'application/pdf',
            'file_size' => 7,
        ]);

        Storage::fake('verification');
        Storage::disk('verification')->put($document->file_path, 'private');

        $this->withToken($token)
            ->getJson("/api/providers/{$provider->id}")
            ->assertOk()
            ->assertJsonPath('data.total_reviews', 0)
            ->assertJsonMissingPath('data.latest_verification_request.documents.0.file_path')
            ->assertJsonMissingPath('data.latest_verification_request.documents.0.file_url');

        $this->withToken($token)
            ->get("/api/providers/{$provider->id}/verification-documents/{$document->id}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $otherProvider = ProviderProfile::create([
            'user_id' => User::factory()->create(['user_type' => 'provider'])->id,
            'business_name' => 'Other Provider',
        ]);

        $this->withToken($token)
            ->getJson("/api/providers/{$otherProvider->id}/verification-documents/{$document->id}/download")
            ->assertNotFound();
    }
}
