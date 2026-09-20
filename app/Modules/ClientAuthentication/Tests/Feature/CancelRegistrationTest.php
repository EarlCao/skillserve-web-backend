<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CancelRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_backing_out_of_verification_discards_the_customer_signup(): void
    {
        Notification::fake();
        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Cancel',
            'last_name' => 'Me',
            'email' => 'cancel-me@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(202);

        $this->assertDatabaseHas('pending_registrations', ['email' => 'cancel-me@example.com']);

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'cancel-me@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseMissing('pending_registrations', ['email' => 'cancel-me@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'cancel-me@example.com']);

        // The email is immediately reusable, and the resend cooldown from
        // the abandoned code does not block the fresh attempt.
        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Cancel',
            'last_name' => 'Me',
            'email' => 'cancel-me@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(202);
    }

    public function test_backing_out_discards_the_provider_signup_and_creates_no_profile(): void
    {
        Notification::fake();
        $this->postJson('/api/client/v1/auth/register-provider', [
            'first_name' => 'Prov',
            'last_name' => 'Cancel',
            'email' => 'prov-cancel@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'specialization' => 'Plumbing',
        ])->assertStatus(202);

        $this->assertDatabaseCount('provider_profiles', 0);

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'prov-cancel@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseMissing('pending_registrations', ['email' => 'prov-cancel@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'prov-cancel@example.com']);
    }

    public function test_an_unverified_account_from_an_older_build_is_still_deleted(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy-unverified@example.com',
            'password' => 'password123',
            'user_type' => 'provider',
            'email_verified_at' => null,
        ]);
        ProviderProfile::create([
            'user_id' => $user->id,
            'specialization' => 'Plumbing',
            'verification_status' => 'pending',
        ]);

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'legacy-unverified@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['email' => 'legacy-unverified@example.com']);
        $this->assertDatabaseMissing('provider_profiles', ['user_id' => $user->id]);
    }

    public function test_verified_accounts_are_never_deleted(): void
    {
        User::factory()->create([
            'email' => 'verified@example.com',
            'password' => 'password123',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'verified@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'verified@example.com']);
    }

    public function test_wrong_password_returns_the_same_response_and_deletes_nothing(): void
    {
        Notification::fake();
        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Keep',
            'last_name' => 'Me',
            'email' => 'keep-me@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(202);

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'keep-me@example.com',
            'password' => 'wrong-password',
        ])->assertOk();

        $this->assertDatabaseHas('pending_registrations', ['email' => 'keep-me@example.com']);
    }

    public function test_unknown_email_returns_the_same_response(): void
    {
        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'ghost@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['email' => 'ghost@example.com']);
    }

    public function test_email_and_password_are_required(): void
    {
        $this->postJson('/api/client/v1/auth/cancel-registration', [])
            ->assertStatus(422);
    }
}
