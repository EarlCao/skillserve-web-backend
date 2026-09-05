<?php

namespace App\Modules\ProviderRecognition\Tests\Feature;

use App\Models\User;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProviderRecognitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_recognition_requires_permission(): void
    {
        $user = User::factory()->create(['status' => 'active', 'user_type' => 'customer']);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/provider-recognition/badges')
            ->assertForbidden();
    }

    public function test_badges_can_be_created_and_listed(): void
    {
        [, $token] = $this->actingRecognitionAdmin(['view provider recognition', 'manage provider badges']);

        $this->withToken($token)
            ->postJson('/api/provider-recognition/badges', [
                'name' => 'Top Rated',
                'slug' => 'top-rated',
                'description' => 'Excellent ratings.',
                'color' => 'warning',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'top-rated');

        $this->withToken($token)
            ->getJson('/api/provider-recognition/badges')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Top Rated');
    }

    public function test_badge_can_be_assigned_removed_and_provider_featured(): void
    {
        [$admin, $token] = $this->actingRecognitionAdmin([
            'view provider recognition', 'assign provider badges', 'manage featured providers',
        ]);
        $providerUser = User::factory()->create(['status' => 'active', 'user_type' => 'provider']);
        $provider = ProviderProfile::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Recognition Provider',
            'verification_status' => 'verified',
            'average_rating' => 4.9,
            'total_reviews' => 10,
        ]);
        $badge = ProviderBadge::create(['name' => 'Trusted', 'slug' => 'trusted', 'color' => 'success']);

        $this->withToken($token)
            ->postJson("/api/provider-recognition/providers/{$provider->id}/badges", ['badge_id' => $badge->id])
            ->assertOk()
            ->assertJsonCount(1, 'data.badges');
        $this->assertDatabaseHas('provider_badge_assignments', [
            'provider_profile_id' => $provider->id,
            'provider_badge_id' => $badge->id,
            'assigned_by' => $admin->id,
        ]);

        $this->withToken($token)
            ->patchJson("/api/provider-recognition/providers/{$provider->id}/featured", ['is_featured' => true])
            ->assertOk()
            ->assertJsonPath('data.is_featured', true);

        $this->withToken($token)
            ->getJson('/api/provider-recognition/providers?is_featured=true')
            ->assertOk()
            ->assertJsonPath('data.0.id', $provider->id);

        $this->withToken($token)
            ->deleteJson("/api/provider-recognition/providers/{$provider->id}/badges/{$badge->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.badges');
    }

    public function test_top_rated_returns_verified_providers(): void
    {
        [, $token] = $this->actingRecognitionAdmin(['view top rated providers']);
        $user = User::factory()->create(['status' => 'active', 'user_type' => 'provider']);
        ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Top Provider',
            'verification_status' => 'verified',
            'average_rating' => 5,
            'total_reviews' => 4,
        ]);

        $this->withToken($token)
            ->getJson('/api/provider-recognition/top-rated')
            ->assertOk()
            ->assertJsonPath('data.0.business_name', 'Top Provider');
    }

    /** @param array<int, string> $permissions */
    private function actingRecognitionAdmin(array $permissions): array
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::findOrCreate('recognition-admin');
        $role->syncPermissions($permissions);
        $user = User::factory()->create([
            'email' => 'recognition-admin@skillserve.test',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return [$user, $user->createToken('test')->plainTextToken];
    }
}
