<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CancelRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backing_out_of_verification_deletes_the_unverified_customer_account(): void
    {
        Notification::fake();
        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Cancel',
            'last_name' => 'Me',
            'email' => 'cancel-me@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'cancel-me@example.com']);

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'cancel-me@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['email' => 'cancel-me@example.com']);

        // The email is immediately reusable — the user can register again.
        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Cancel',
            'last_name' => 'Me',
            'email' => 'cancel-me@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
    }

    public function test_backing_out_deletes_the_unverified_provider_account_and_profile(): void
    {
        Notification::fake();
        $this->postJson('/api/client/v1/auth/register-provider', [
            'first_name' => 'Prov',
            'last_name' => 'Cancel',
            'email' => 'prov-cancel@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'specialization' => 'Plumbing',
        ])->assertCreated();

        $userId = User::query()->where('email', 'prov-cancel@example.com')->value('id');
        $this->assertNotNull($userId);
        $this->assertDatabaseHas('provider_profiles', ['user_id' => $userId]);

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'prov-cancel@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['email' => 'prov-cancel@example.com']);
        $this->assertDatabaseMissing('provider_profiles', ['user_id' => $userId]);
    }

    public function test_verified_accounts_are_never_deleted(): void
    {
        $user = User::factory()->create([
            'email' => 'verified@example.com',
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
        ])->assertCreated();

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'keep-me@example.com',
            'password' => 'wrong-password',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'keep-me@example.com']);
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
