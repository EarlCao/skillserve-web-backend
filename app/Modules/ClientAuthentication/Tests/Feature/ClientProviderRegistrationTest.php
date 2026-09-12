<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientProviderRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_registration_creates_roleless_provider_with_profile_and_session(): void
    {
        $response = $this->postJson('/api/client/v1/auth/register-provider', [
            'first_name' => 'Alex',
            'last_name' => 'Provider',
            'email' => 'provider@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Alex Repairs',
            'specialization' => 'Home Repair',
            'experience_years' => 3,
            'bio' => 'Fixing things since 2023.',
        ]);

        $response->assertCreated()->assertJsonPath('data.user.user_type', 'provider');

        $user = User::query()->where('email', 'provider@example.com')->firstOrFail();

        $this->assertSame('provider', $user->user_type);
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertFalse($user->roles()->exists(), 'Mobile providers must remain roleless.');

        $profile = ProviderProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Alex Repairs', $profile->business_name);
        $this->assertSame('Home Repair', $profile->specialization);
        $this->assertSame(3, $profile->experience_years);
        $this->assertSame('pending', $profile->verification_status);

        $this->assertNotNull($response->json('data.token'));
        $this->assertNotNull($response->json('data.refresh_token'));
    }

    public function test_registered_provider_can_login_and_refresh(): void
    {
        $this->postJson('/api/client/v1/auth/register-provider', [
            'first_name' => 'Alex',
            'last_name' => 'Provider',
            'email' => 'provider@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'specialization' => 'Home Repair',
        ])->assertCreated();

        // New providers start unverified and must confirm the emailed OTP.
        $user = User::query()->where('email', 'provider@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());

        $code = '654321';
        $user->forceFill([
            'email_otp_hash' => Hash::make($code),
            'email_otp_expires_at' => now()->addMinutes(10),
            'email_otp_attempts' => 0,
        ])->save();

        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'provider@example.com',
            'code' => $code,
        ])->assertOk();

        $login = $this->postJson('/api/client/v1/auth/login', [
            'email' => 'provider@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.user.user_type', 'provider')->json('data');

        $this->postJson('/api/client/v1/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ])->assertOk();
    }

    public function test_provider_registration_validates_required_specialization(): void
    {
        $this->postJson('/api/client/v1/auth/register-provider', [
            'first_name' => 'Alex',
            'last_name' => 'Provider',
            'email' => 'provider2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertJsonValidationErrors(['specialization']);
    }

    public function test_mobile_provider_token_is_rejected_by_client_marketplace_gate(): void
    {
        $session = $this->postJson('/api/client/v1/auth/register-provider', [
            'first_name' => 'Alex',
            'last_name' => 'Provider',
            'email' => 'provider3@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'specialization' => 'Home Repair',
        ])->assertCreated()->json('data');

        $this->getJson('/api/client/v1/bookings', [
            'Authorization' => 'Bearer '.$session['token'],
        ])->assertForbidden();
    }

    public function test_mobile_provider_cannot_authenticate_on_admin_surface(): void
    {
        $this->postJson('/api/client/v1/auth/register-provider', [
            'first_name' => 'Alex',
            'last_name' => 'Provider',
            'email' => 'provider4@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'specialization' => 'Home Repair',
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'provider4@example.com',
            'password' => 'password123',
        ])->assertUnauthorized();
    }
}
