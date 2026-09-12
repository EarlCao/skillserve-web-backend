<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use App\Modules\ClientAuthentication\Notifications\ClientEmailVerificationNotification;
use App\Modules\ClientAuthentication\Notifications\ClientPasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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
        ], $attributes));
    }

    private function login(User $user, string $password = 'password123'): array
    {
        return $this->postJson('/api/client/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('data');
    }

    public function test_registration_creates_a_roleless_active_customer_and_session(): void
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
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'alex@example.com')
            ->assertJsonPath('data.user.name', 'Alex Customer')
            ->assertJsonPath('data.user.status', 'active')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonStructure(['data' => ['token', 'refresh_token', 'expires_at', 'refresh_expires_at']]);

        $user = User::where('email', 'alex@example.com')->firstOrFail();
        $this->assertSame('customer', $user->user_type);
        $this->assertFalse($user->roles()->exists());
        Notification::assertSentTo($user, ClientEmailOtpNotification::class);
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

    public function test_unverified_clients_cannot_login_or_refresh_but_can_verify_from_the_provider_mounted_link(): void
    {
        Notification::fake();
        $registration = $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Unverified',
            'last_name' => 'Customer',
            'email' => 'unverified@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
        $user = User::query()->where('email', 'unverified@example.com')->firstOrFail();

        $this->postJson('/api/client/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(403)->assertJsonPath('message', 'Please verify your email address before signing in.');

        $this->postJson('/api/client/v1/auth/refresh', [
            'refresh_token' => $registration->json('data.refresh_token'),
        ])->assertStatus(403)->assertJsonPath('message', 'Please verify your email address before refreshing your session.');

        $token = $registration->json('data.token');
        $this->withToken($token)
            ->postJson('/api/client/v1/auth/verification-notification')
            ->assertStatus(202)
            ->assertJsonPath('data.email_verified', false);
        $this->withToken($token)
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
