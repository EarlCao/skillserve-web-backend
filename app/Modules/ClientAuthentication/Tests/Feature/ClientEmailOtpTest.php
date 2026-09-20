<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use App\Modules\ClientAuthentication\Services\ClientEmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientEmailOtpTest extends TestCase
{
    use RefreshDatabase;

    private function registerCustomer(string $email = 'otp@example.com'): array
    {
        Notification::fake();

        return $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Alex',
            'last_name' => 'Customer',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(202)->json('data');
    }

    private function forceCode(string $email, string $code = '123456'): PendingRegistration
    {
        $registration = PendingRegistration::query()->where('email', $email)->firstOrFail();
        $registration->forceFill([
            'email_otp_hash' => Hash::make($code),
            'email_otp_expires_at' => now()->addMinutes(10),
            'email_otp_attempts' => 0,
        ])->save();

        return $registration;
    }

    public function test_registration_sends_a_six_digit_otp_and_creates_no_account_yet(): void
    {
        $data = $this->registerCustomer();

        $this->assertTrue($data['verification_required']);
        $this->assertDatabaseMissing('users', ['email' => 'otp@example.com']);

        $registration = PendingRegistration::query()->where('email', 'otp@example.com')->firstOrFail();
        $this->assertNotEmpty($registration->email_otp_hash);
        $this->assertNotNull($registration->email_otp_expires_at);

        Notification::assertSentTo($registration, ClientEmailOtpNotification::class);
    }

    public function test_correct_otp_creates_the_account_and_wrong_otp_is_rejected(): void
    {
        $this->registerCustomer();
        $this->forceCode('otp@example.com');

        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'otp@example.com',
            'code' => '000000',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'otp@example.com']);

        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'otp@example.com',
            'code' => '123456',
        ])->assertOk()->assertJsonPath('data.user.email_verified', true);

        $user = User::query()->where('email', 'otp@example.com')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->email_otp_hash);
        $this->assertDatabaseMissing('pending_registrations', ['email' => 'otp@example.com']);
    }

    public function test_an_expired_code_cannot_be_used(): void
    {
        $this->registerCustomer();
        $this->forceCode('otp@example.com');

        PendingRegistration::query()
            ->where('email', 'otp@example.com')
            ->update(['email_otp_expires_at' => now()->subMinute()]);

        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'otp@example.com',
            'code' => '123456',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'otp@example.com']);
    }

    public function test_attempts_are_capped(): void
    {
        $this->registerCustomer();
        $this->forceCode('otp@example.com');

        for ($attempt = 0; $attempt < ClientEmailOtpService::MAX_ATTEMPTS; $attempt++) {
            $this->postJson('/api/client/v1/auth/verify-otp', [
                'email' => 'otp@example.com',
                'code' => '000000',
            ])->assertStatus(422);
        }

        // Even the right code is refused once the attempts are spent.
        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'otp@example.com',
            'code' => '123456',
        ])->assertStatus(429);
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

        $registration = PendingRegistration::query()->where('email', 'otp@example.com')->firstOrFail();
        Notification::assertSentTo($registration, ClientEmailOtpNotification::class);
    }

    public function test_replaying_the_same_code_does_not_create_a_second_account(): void
    {
        $this->registerCustomer();
        $this->forceCode('otp@example.com');

        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'otp@example.com',
            'code' => '123456',
        ])->assertOk();

        // The sign-up is consumed; replaying the code must not duplicate it.
        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'otp@example.com',
            'code' => '123456',
        ])->assertStatus(422);

        $this->assertSame(1, User::query()->where('email', 'otp@example.com')->count());
    }

    public function test_resend_for_an_unknown_email_is_not_found(): void
    {
        $this->postJson('/api/client/v1/auth/resend-otp', [
            'email' => 'nobody@example.com',
        ])->assertStatus(404);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
