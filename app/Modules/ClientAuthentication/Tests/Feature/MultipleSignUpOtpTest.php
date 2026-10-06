<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Several people signing up at once — customers and providers, by email and
 * by Google, from different phones — each get their own code, at the address
 * they signed up with, and each code opens only its own sign-up.
 */
class MultipleSignUpOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-web-client-id.apps.googleusercontent.com']);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** The 6-digit code in the email sent to $email. */
    private function codeSentTo(string $email): string
    {
        $registration = PendingRegistration::query()->where('email', $email)->firstOrFail();
        $code = null;

        Notification::assertSentTo(
            $registration,
            ClientEmailOtpNotification::class,
            function (ClientEmailOtpNotification $notification, array $channels, object $notifiable) use ($email, &$code): bool {
                $this->assertSame($email, $notifiable->routeNotificationFor('mail'));
                $mail = $notification->toMail($notifiable);
                preg_match('/\*\*(\d{6})\*\*/', implode("\n", $mail->introLines), $match);
                $code = $match[1] ?? null;

                return $code !== null;
            },
        );

        return $code;
    }

    public function test_every_customer_and_provider_gets_their_own_code_at_their_own_address(): void
    {
        Notification::fake();

        $signUps = [
            ['/api/client/v1/auth/register', 'customer.one@example.com', []],
            ['/api/client/v1/auth/register', 'customer.two@example.com', []],
            ['/api/client/v1/auth/register', 'customer.three@example.com', []],
            ['/api/client/v1/auth/register-provider', 'provider.one@example.com', ['specialization' => 'Plumbing']],
            ['/api/client/v1/auth/register-provider', 'provider.two@example.com', ['specialization' => 'Aircon repair']],
            ['/api/client/v1/auth/register-provider', 'provider.three@example.com', ['specialization' => 'Cleaning']],
        ];

        foreach ($signUps as [$endpoint, $email, $extra]) {
            $this->postJson($endpoint, [
                'first_name' => 'Test',
                'last_name' => 'Person',
                'email' => $email,
                'birthday' => '1995-04-02',
                ...$extra,
            ])->assertStatus(202)->assertJsonPath('data.email', $email);
        }

        // One email per sign-up, nobody receives someone else's.
        Notification::assertSentTimes(ClientEmailOtpNotification::class, count($signUps));
        $codes = [];
        foreach ($signUps as [, $email]) {
            $codes[$email] = $this->codeSentTo($email);
        }

        // Each code works for its own address only.
        $this->postJson('/api/client/v1/auth/verify-otp', [
            'email' => 'customer.one@example.com',
            'code' => $codes['provider.one@example.com'] === $codes['customer.one@example.com'] ? '000000' : $codes['provider.one@example.com'],
        ])->assertStatus(422);

        foreach ($codes as $email => $code) {
            $this->postJson('/api/client/v1/auth/verify-otp', ['email' => $email, 'code' => $code])
                ->assertOk()
                ->assertJsonPath('data.password_required', true);
        }
    }

    public function test_google_sign_ups_get_the_code_at_the_google_address(): void
    {
        Notification::fake();
        // Each phone signs in with a different Google account.
        $email = null;
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => function () use (&$email) {
            return Http::response([
                'aud' => 'test-web-client-id.apps.googleusercontent.com',
                'sub' => 'sub-'.$email,
                'email' => $email,
                'email_verified' => 'true',
                'exp' => (string) (time() + 3600),
            ]);
        }]);

        foreach (['first.google@gmail.com' => 'customer', 'second.google@gmail.com' => 'provider'] as $email => $role) {

            $this->postJson('/api/client/v1/auth/google/register', [
                'id_token' => str_repeat('a', 30),
                'first_name' => 'Google',
                'last_name' => 'Person',
                'role' => $role,
                'specialization' => $role === 'provider' ? 'Plumbing' : null,
                'birthday' => '1995-04-02',
            ])->assertStatus(202)->assertJsonPath('data.email', $email);

            $this->codeSentTo($email);
        }

        $this->assertSame(0, User::query()->whereIn('email', ['first.google@gmail.com', 'second.google@gmail.com'])->count());
    }
}
