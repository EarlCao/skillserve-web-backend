<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientEmailOtpTest extends TestCase
{
    use RefreshDatabase;

    private string $registeredSessionToken = '';

    private function registerCustomer(string $email = 'otp@example.com'): array
    {
        Notification::fake();

        $data = $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Alex',
            'last_name' => 'Customer',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json('data');

        $this->registeredSessionToken = $data['token'] ?? '';

        return $data;
    }

    public function test_registration_sends_a_six_digit_otp_and_leaves_email_unverified(): void
    {
        $this->registerCustomer();

        $user = User::query()->where('email', 'otp@example.com')->firstOrFail();

        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertNotNull($user->email_otp_hash);
        $this->assertNotNull($user->email_otp_expires_at);

        Notification::assertSentTo($user, ClientEmailOtpNotification::class);
    }

    public function test_correct_otp_verifies_email_and_wrong_otp_is_rejected(): void
    {
        $this->registerCustomer();
        $user = User::query()->where('email', 'otp@example.com')->firstOrFail();

        // Grab the issued code by re-issuing with a known value.
        $code = '123456';
        $user->forceFill([
            'email_otp_hash' => Hash::make($code),
            'email_otp_expires_at' => now()->addMinutes(10),
            'email_otp_attempts' => 0,
        ])->save();

        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'otp@example.com',
            'code' => '000000',
        ])->assertStatus(422);

        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'otp@example.com',
            'code' => $code,
        ])->assertOk()->assertJsonPath('data.email_verified', true);

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->email_otp_hash);
    }

    public function test_resend_is_rate_limited_and_replaces_the_code(): void
    {
        $this->registerCustomer();
        Notification::fake();

        // Registration just issued a code, so an immediate resend is cooled down.
        $this->postJson('/api/client/v1/auth/resend-otp', [
            'email' => 'otp@example.com',
        ])->assertStatus(429);

        // Once the cooldown passes, a resend is accepted.
        Cache::forget('email-otp:cooldown:otp@example.com');

        $this->postJson('/api/client/v1/auth/resend-otp', [
            'email' => 'otp@example.com',
        ])->assertStatus(202);
    }

    public function test_google_signin_rejects_tokens_with_wrong_audience(): void
    {
        config(['services.google.client_id' => 'expected-client-id.apps.googleusercontent.com']);

        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'different-client-id.apps.googleusercontent.com',
                'email' => 'g@example.com',
                'email_verified' => 'true',
                'exp' => (string) (time() + 3600),
                'sub' => '123',
            ]),
        ]);

        $this->postJson('/api/client/v1/auth/google', [
            'id_token' => str_repeat('a', 40),
        ])->assertStatus(401);
    }

    public function test_google_signin_creates_verified_customer_and_session(): void
    {
        config(['services.google.client_id' => 'expected-client-id.apps.googleusercontent.com']);
        Notification::fake();

        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'expected-client-id.apps.googleusercontent.com',
                'email' => 'newgoogle@example.com',
                'email_verified' => 'true',
                'exp' => (string) (time() + 3600),
                'sub' => '123',
                'given_name' => 'Goo',
                'family_name' => 'Gler',
                'name' => 'Goo Gler',
            ]),
        ]);

        $this->postJson('/api/client/v1/auth/google', [
            'id_token' => str_repeat('a', 40),
        ])->assertOk()->assertJsonPath('data.user.email', 'newgoogle@example.com');

        $user = User::query()->where('email', 'newgoogle@example.com')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('customer', $user->user_type);
        $this->assertFalse($user->roles()->exists());
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
