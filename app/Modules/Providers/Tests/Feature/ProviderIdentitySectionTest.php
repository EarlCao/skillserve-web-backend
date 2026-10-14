<?php

namespace App\Modules\Providers\Tests\Feature;

use App\Models\User;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The provider's National ID on their admin profile. */
class ProviderIdentitySectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_id_reviewers_see_a_verified_providers_national_id_on_the_profile(): void
    {
        $provider = $this->verifiedProviderWithId();
        $reviewer = $this->token(['view providers', 'view identity verifications']);
        $providersOnly = $this->token(['view providers']);

        $this->withToken($reviewer)->getJson("/api/providers/{$provider->id}")
            ->assertOk()
            ->assertJsonPath('data.identity.status', 'verified')
            ->assertJsonPath('data.identity.full_name', 'Juan Dela Cruz')
            ->assertJsonPath('data.identity.id_number_last4', '4321')
            ->assertJsonCount(1, 'data.identity.documents')
            ->assertJsonPath('data.identity.documents.0.document_type', 'id_front');

        // Without the identity permission the ID is not shown at all.
        Auth::forgetGuards();
        $this->withToken($providersOnly)->getJson("/api/providers/{$provider->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.identity');
    }

    private function verifiedProviderWithId(): ProviderProfile
    {
        $user = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $profile = ProviderProfile::create(['user_id' => $user->id, 'business_name' => 'Cool Aircon', 'verification_status' => 'verified']);
        $record = IdentityVerification::create([
            'user_id' => $user->id,
            'status' => IdentityVerification::VERIFIED,
            'id_number_last4' => '4321',
            'full_name' => 'Juan Dela Cruz',
            'birthdate' => '1995-04-02',
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
        ]);
        $record->documents()->create([
            'document_type' => 'id_front',
            'file_name' => 'front.jpg',
            'file_path' => 'ids/front.jpg',
            'file_mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);

        return $profile;
    }

    /** @param  array<int, string>  $permissions */
    private function token(array $permissions): string
    {
        foreach (['view providers', 'view identity verifications', 'manage providers', ...$permissions] as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::findOrCreate('id-'.Str::random(6));
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user->createToken('admin')->plainTextToken;
    }
}
