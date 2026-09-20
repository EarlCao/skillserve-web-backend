<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientProviderRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Register a provider and confirm the emailed code, returning the
     * session the verification issued.
     *
     * @return array<string, mixed>
     */
    private function registerAndVerifyProvider(string $email, array $overrides = []): array
    {
        Notification::fake();

        $this->postJson('/api/client/v1/auth/register-provider', array_merge([
            'first_name' => 'Alex',
            'last_name' => 'Provider',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'specialization' => 'Home Repair',
        ], $overrides))->assertStatus(202);

        PendingRegistration::query()->where('email', $email)->firstOrFail()->forceFill([
            'email_otp_hash' => Hash::make('654321'),
            'email_otp_expires_at' => now()->addMinutes(10),
            'email_otp_attempts' => 0,
        ])->save();

        return $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => $email,
            'code' => '654321',
        ])->assertOk()->json('data');
    }

    public function test_provider_signup_is_parked_until_the_code_is_confirmed(): void
    {
        Notification::fake();

        $this->postJson('/api/client/v1/auth/register-provider', [
            'first_name' => 'Alex',
            'last_name' => 'Provider',
            'email' => 'provider@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Alex Repairs',
            'specialization' => 'Home Repair',
            'experience_years' => 3,
            'bio' => 'Fixing things since 2023.',
        ])->assertStatus(202)->assertJsonPath('data.user_type', 'provider');

        $this->assertDatabaseMissing('users', ['email' => 'provider@example.com']);
        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertDatabaseHas('pending_registrations', [
            'email' => 'provider@example.com',
            'role_id' => 3,
            'specialization' => 'Home Repair',
            'business_name' => 'Alex Repairs',
            'experience_years' => 3,
        ]);
    }

    public function test_verification_creates_the_roleless_provider_with_profile_and_session(): void
    {
        $session = $this->registerAndVerifyProvider('provider@example.com', [
            'business_name' => 'Alex Repairs',
            'experience_years' => 3,
            'bio' => 'Fixing things since 2023.',
        ]);

        $this->assertSame('provider', $session['user']['user_type']);

        $user = User::query()->where('email', 'provider@example.com')->firstOrFail();

        $this->assertSame('provider', $user->user_type);
        $this->assertSame('active', $user->status);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertFalse($user->roles()->exists(), 'Mobile providers must remain roleless.');

        $profile = ProviderProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Alex Repairs', $profile->business_name);
        $this->assertSame('Home Repair', $profile->specialization);
        $this->assertSame(3, $profile->experience_years);
        $this->assertSame('pending', $profile->verification_status);

        $this->assertNotNull($session['token']);
        $this->assertNotNull($session['refresh_token']);
    }

    public function test_registered_provider_can_login_and_refresh(): void
    {
        $this->registerAndVerifyProvider('provider@example.com');

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
        $session = $this->registerAndVerifyProvider('provider3@example.com');

        $this->getJson('/api/client/v1/bookings', [
            'Authorization' => 'Bearer '.$session['token'],
        ])->assertForbidden();
    }

    public function test_mobile_provider_cannot_authenticate_on_admin_surface(): void
    {
        $this->registerAndVerifyProvider('provider4@example.com');

        $this->postJson('/api/auth/login', [
            'email' => 'provider4@example.com',
            'password' => 'password123',
        ])->assertUnauthorized();
    }
}
