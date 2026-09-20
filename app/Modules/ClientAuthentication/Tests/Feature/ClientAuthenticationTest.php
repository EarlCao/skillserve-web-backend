<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use App\Modules\ClientAuthentication\Notifications\ClientEmailVerificationNotification;
use App\Modules\ClientAuthentication\Notifications\ClientPasswordResetNotification;
use App\Modules\ClientAuthentication\Services\ClientSessionService;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password123'),
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function login(User $user, string $password = 'password123'): array
    {
        return $this->postJson('/api/client/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('data');
    }

    /**
     * Force a known code onto a parked sign-up so the test can confirm it.
     */
    private function setPendingCode(string $email, string $code = '123456'): PendingRegistration
    {
        $registration = PendingRegistration::query()->where('email', $email)->firstOrFail();
        $registration->forceFill([
            'email_otp_hash' => Hash::make($code),
            'email_otp_expires_at' => now()->addMinutes(10),
            'email_otp_attempts' => 0,
        ])->save();

        return $registration;
    }

    public function test_registration_parks_the_signup_without_creating_an_account(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Alex',
            'last_name' => 'Customer',
            'email' => 'alex@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('data.verification_required', true)
            ->assertJsonPath('data.email', 'alex@example.com')
            ->assertJsonPath('data.user_type', 'customer');

        // Nothing is issued and nothing is stored in `users` until the code
        // is confirmed, so a half-finished sign-up holds no email hostage.
        $this->assertNull($response->json('data.token'));
        $this->assertDatabaseMissing('users', ['email' => 'alex@example.com']);
        $this->assertDatabaseHas('pending_registrations', ['email' => 'alex@example.com', 'role_id' => 4]);

        $registration = PendingRegistration::query()->where('email', 'alex@example.com')->firstOrFail();
        Notification::assertSentTo($registration, ClientEmailOtpNotification::class);
    }

    public function test_verifying_the_code_creates_the_customer_and_signs_in(): void
    {
        Notification::fake();

        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Alex',
            'last_name' => 'Customer',
            'email' => 'alex@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(202);

        $this->setPendingCode('alex@example.com');

        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'alex@example.com',
            'code' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'alex@example.com')
            ->assertJsonPath('data.user.name', 'Alex Customer')
            ->assertJsonPath('data.user.status', 'active')
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonStructure(['data' => ['token', 'refresh_token', 'expires_at', 'refresh_expires_at']]);

        $user = User::where('email', 'alex@example.com')->firstOrFail();
        $this->assertSame('customer', $user->user_type);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertFalse($user->roles()->exists());
        $this->assertTrue(Hash::check('password123', $user->password), 'The registration password must carry over.');
        $this->assertDatabaseMissing('pending_registrations', ['email' => 'alex@example.com']);

        $this->postJson('/api/client/v1/auth/login', [
            'email' => 'alex@example.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_abandoned_signup_leaves_the_email_free_to_register_again(): void
    {
        Notification::fake();

        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Alex',
            'last_name' => 'Customer',
            'email' => 'retry@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(202);

        // Signing up again while the first code is still fresh keeps that
        // code alive rather than silently replacing it.
        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Alex',
            'last_name' => 'Customer',
            'email' => 'retry@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(429)->assertJsonPath('meta.verification_required', true);

        $this->assertDatabaseHas('pending_registrations', ['email' => 'retry@example.com']);

        // The user closes the app instead of entering the code, then signs
        // up again — previously this failed with "email already taken".
        Cache::forget('email-otp:cooldown:retry@example.com');

        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Alexandra',
            'last_name' => 'Customer',
            'email' => 'retry@example.com',
            'password' => 'password456',
            'password_confirmation' => 'password456',
        ])->assertStatus(202);

        $this->assertSame(1, PendingRegistration::query()->where('email', 'retry@example.com')->count());

        $this->setPendingCode('retry@example.com', '654321');
        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'retry@example.com',
            'code' => '654321',
        ])->assertOk()->assertJsonPath('data.user.first_name', 'Alexandra');
    }

    public function test_login_tells_an_unverified_signup_apart_from_a_wrong_password(): void
    {
        Notification::fake();

        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Half',
            'last_name' => 'Done',
            'email' => 'half-done@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(202);

        $this->postJson('/api/client/v1/auth/login', [
            'email' => 'half-done@example.com',
            'password' => 'password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('meta.verification_required', true)
            ->assertJsonPath('meta.email', 'half-done@example.com');

        // A wrong password must not reveal that the sign-up exists.
        $this->postJson('/api/client/v1/auth/login', [
            'email' => 'half-done@example.com',
            'password' => 'not-the-password',
        ])->assertStatus(401);
    }

    public function test_registration_validates_required_fields_and_duplicate_email(): void
    {
        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => '',
            'last_name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['first_name', 'last_name', 'email', 'password']]);

        $this->customer(['email' => 'duplicate@example.com']);

        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Alex',
            'last_name' => 'Customer',
            'email' => 'duplicate@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_client_login_issues_only_client_ability_and_rejects_inactive_accounts(): void
    {
        $user = $this->customer(['email' => 'customer@example.com']);
        $data = $this->login($user);

        $this->assertSame(['client:auth'], $user->tokens()->firstOrFail()->abilities);
        $this->assertNotEmpty($data['refresh_token']);

        $user->update(['status' => 'suspended']);

        $this->postJson('/api/client/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(403);
    }

    public function test_accounts_left_unverified_by_older_builds_are_gated_until_they_verify(): void
    {
        Notification::fake();

        // Registrations are deferred now, but accounts created before that
        // can still sit unverified in `users`; they must stay locked out
        // and must still be able to verify from the emailed link.
        $user = $this->customer(['email' => 'unverified@example.com', 'email_verified_at' => null]);
        $session = app(ClientSessionService::class)->issue($user);

        $this->postJson('/api/client/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(403)->assertJsonPath('message', 'Please verify your email address before signing in.');

        $this->postJson('/api/client/v1/auth/refresh', [
            'refresh_token' => $session['refresh_token'],
        ])->assertStatus(403)->assertJsonPath('message', 'Please verify your email address before refreshing your session.');

        $this->withToken($session['token'])
            ->postJson('/api/client/v1/auth/verification-notification')
            ->assertStatus(202)
            ->assertJsonPath('data.email_verified', false);
        $this->withToken($session['token'])
            ->getJson('/api/client/v1/notifications')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Please verify your email address before using client marketplace features.');

        $verification = null;
        Notification::assertSentTo($user, ClientEmailVerificationNotification::class, function (ClientEmailVerificationNotification $notification) use (&$verification): bool {
            $verification = $notification;

            return true;
        });

        $url = $verification->verificationUrl($user);
        $invalidPath = str_replace(sha1($user->email), 'invalid', parse_url($url, PHP_URL_PATH));
        $this->getJson($invalidPath.'?'.parse_url($url, PHP_URL_QUERY))->assertForbidden();

        $this->getJson(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);

        $this->postJson('/api/client/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_an_unverified_legacy_account_can_verify_with_an_otp_and_is_signed_in(): void
    {
        $user = $this->customer(['email' => 'legacy-otp@example.com', 'email_verified_at' => null]);
        $user->forceFill([
            'email_otp_hash' => Hash::make('112233'),
            'email_otp_expires_at' => now()->addMinutes(10),
            'email_otp_attempts' => 0,
        ])->save();

        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'legacy-otp@example.com',
            'code' => '112233',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonStructure(['data' => ['token', 'refresh_token']]);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_client_and_admin_authentication_surfaces_are_isolated(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $admin->assignRole('admin');
        $customer = $this->customer(['email' => 'customer@example.com']);

        $adminToken = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->assertOk()->json('data.token');
        $clientToken = $this->login($customer)['token'];

        $this->withToken($adminToken)->getJson('/api/client/v1/auth/me')->assertStatus(403);
        Auth::forgetGuards();
        $this->withToken($clientToken)->getJson('/api/auth/me')->assertStatus(403);
        Auth::forgetGuards();
        $this->withToken($adminToken)->getJson('/api/auth/me')->assertOk();
    }

    public function test_refresh_rotation_revokes_the_presented_token_and_reuse_revokes_the_family(): void
    {
        $user = $this->customer();
        $first = $this->login($user);

        $second = $this->postJson('/api/client/v1/auth/refresh', [
            'refresh_token' => $first['refresh_token'],
        ])->assertOk()->json('data');

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);

        $this->postJson('/api/client/v1/auth/refresh', [
            'refresh_token' => $first['refresh_token'],
        ])->assertStatus(401);

        $this->postJson('/api/client/v1/auth/refresh', [
            'refresh_token' => $second['refresh_token'],
        ])->assertStatus(401);
    }

    public function test_change_password_revokes_access_and_refresh_sessions(): void
    {
        $user = $this->customer();
        $session = $this->login($user);

        $this->withToken($session['token'])
            ->postJson('/api/client/v1/auth/change-password', [
                'current_password' => 'password123',
                'password' => 'newpassword456',
                'password_confirmation' => 'newpassword456',
            ])
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($session['token'])->getJson('/api/client/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/client/v1/auth/refresh', [
            'refresh_token' => $session['refresh_token'],
        ])->assertStatus(401);

        $this->postJson('/api/client/v1/auth/login', [
            'email' => $user->email,
            'password' => 'newpassword456',
        ])->assertOk();
    }

    public function test_password_reset_request_and_completion_use_the_client_broker_and_revoke_sessions(): void
    {
        Notification::fake();
        $user = $this->customer(['email' => 'reset@example.com']);
        $session = $this->login($user);

        $this->postJson('/api/client/v1/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(202);

        $token = null;
        Notification::assertSentTo($user, ClientPasswordResetNotification::class, function (ClientPasswordResetNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->assertNotNull($token);
        $this->postJson('/api/client/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'resetpassword789',
            'password_confirmation' => 'resetpassword789',
        ])->assertOk();

        Auth::forgetGuards();
        $this->withToken($session['token'])->getJson('/api/client/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/client/v1/auth/login', [
            'email' => $user->email,
            'password' => 'resetpassword789',
        ])->assertOk();
    }

    public function test_providers_can_reset_their_password_too(): void
    {
        Notification::fake();
        $provider = User::factory()->create([
            'email' => 'provider-reset@example.com',
            'password' => Hash::make('password123'),
            'user_type' => 'provider',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        ProviderProfile::create([
            'user_id' => $provider->id,
            'specialization' => 'Home Repair',
            'verification_status' => 'pending',
        ]);

        $this->postJson('/api/client/v1/auth/forgot-password', ['email' => $provider->email])
            ->assertStatus(202);

        $token = null;
        Notification::assertSentTo($provider, ClientPasswordResetNotification::class, function (ClientPasswordResetNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->assertNotNull($token, 'Provider accounts must receive a reset link.');

        $this->postJson('/api/client/v1/auth/reset-password', [
            'token' => $token,
            'email' => $provider->email,
            'password' => 'providerpass789',
            'password_confirmation' => 'providerpass789',
        ])->assertOk();

        $this->postJson('/api/client/v1/auth/login', [
            'email' => $provider->email,
            'password' => 'providerpass789',
        ])->assertOk();
    }

    public function test_suspension_invalidates_refresh_sessions_and_blocks_existing_access_tokens(): void
    {
        $user = $this->customer();
        $session = $this->login($user);
        $user->update(['status' => 'suspended']);

        $this->withToken($session['token'])
            ->getJson('/api/client/v1/auth/me')
            ->assertStatus(403);
        $this->postJson('/api/client/v1/auth/refresh', [
            'refresh_token' => $session['refresh_token'],
        ])->assertStatus(403);
    }
}
