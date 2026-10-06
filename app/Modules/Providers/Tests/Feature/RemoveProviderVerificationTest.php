<?php

namespace App\Modules\Providers\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Notifications\ProviderAccountNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PATCH /api/providers/{provider}/verification/remove — an administrator
 * takes a provider's verified status away.
 */
class RemoveProviderVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  array<int, string>  $permissions */
    private function adminToken(array $permissions): string
    {
        foreach (['manage providers', 'view providers', 'verify providers'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::create(['name' => 'reviewer-'.count($permissions)]);
        $role->syncPermissions($permissions);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('test')->plainTextToken;
    }

    private function provider(string $status = 'verified'): ProviderProfile
    {
        return ProviderProfile::create([
            'user_id' => User::factory()->create(['user_type' => 'provider', 'status' => 'active'])->id,
            'business_name' => 'Verified Plumbing',
            'verification_status' => $status,
            'verified_at' => $status === 'verified' ? now() : null,
        ]);
    }

    public function test_removing_verification_makes_the_provider_unverified_and_tells_them(): void
    {
        Notification::fake();
        $provider = $this->provider();

        $this->withToken($this->adminToken(['view providers', 'verify providers']))
            ->patchJson("/api/providers/{$provider->id}/verification/remove")
            ->assertOk()
            ->assertJsonPath('message', 'Provider verification removed.');

        $provider->refresh();
        $this->assertSame('unverified', $provider->verification_status);
        $this->assertNull($provider->verified_at);
        $this->assertNull($provider->verified_by);
        Notification::assertSentTo($provider->user, ProviderAccountNotification::class);
    }

    public function test_only_a_verified_provider_can_lose_verification(): void
    {
        $provider = $this->provider('pending');

        $this->withToken($this->adminToken(['view providers', 'verify providers']))
            ->patchJson("/api/providers/{$provider->id}/verification/remove")
            ->assertUnprocessable();

        $this->assertSame('pending', $provider->fresh()->verification_status);
    }

    public function test_it_needs_the_verify_providers_permission(): void
    {
        $provider = $this->provider();

        $this->patchJson("/api/providers/{$provider->id}/verification/remove")->assertUnauthorized();

        $this->withToken($this->adminToken(['view providers']))
            ->patchJson("/api/providers/{$provider->id}/verification/remove")
            ->assertForbidden();

        $this->assertSame('verified', $provider->fresh()->verification_status);
    }
}
