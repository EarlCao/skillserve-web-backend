<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * OTP_DRIVER=twilio: Twilio Verify emails and checks the sign-up and
 * password-reset codes, for customers and providers, as often as they are
 * requested (outside the 60-second resend window).
 */
class TwilioVerifyOtpTest extends TestCase
{
    use RefreshDatabase;

    /** The code "Twilio" sent to each address, and every request it got. */
    private array $codes = [];

    /** When set, Twilio answers every send with this error. */
    private ?array $sendError = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'client-auth.otp_driver' => 'twilio',
            'services.twilio.account_sid' => 'ACtest',
            'services.twilio.auth_token' => 'secret',
            'services.twilio.verify_service_sid' => 'VAtest',
        ]);
        Notification::fake();
        $this->fakeTwilio();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** A Verify service that sends a fresh code per address and approves only it. */
    private function fakeTwilio(): void
    {
        Http::fake(function (Request $request) {
            $sendError = $this->sendError;
            $form = $request->data();

            if (str_ends_with($request->url(), '/Services/VAtest/Verifications')) {
                if ($sendError) {
                    return Http::response($sendError, $sendError['status']);
                }
                $this->codes[$form['To']] = (string) random_int(100000, 999999);

                return Http::response(['status' => 'pending', 'channel' => 'email', 'to' => $form['To']], 201);
            }

            if (str_ends_with($request->url(), '/Services/VAtest/VerificationCheck')) {
                $sent = $this->codes[$form['To']] ?? null;
                if ($sent === null) {
                    return Http::response(['code' => 20404, 'message' => 'not found'], 404);
                }
                $approved = $sent === $form['Code'];
                if ($approved) {
                    unset($this->codes[$form['To']]);
                }

                return Http::response(['status' => $approved ? 'approved' : 'pending', 'valid' => $approved]);
            }

            return Http::response([], 500);
        });
    }

    private function sends(string $email): int
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/Verifications') && ($request->data()['To'] ?? null) === $email)->count();
    }

    public function test_customers_and_providers_get_their_code_from_twilio_and_finish_signing_up(): void
    {
        foreach ([
            ['/api/client/v1/auth/register', 'buyer@gmail.com', []],
            ['/api/client/v1/auth/register-provider', 'fixer@gmail.com', ['specialization' => 'Plumbing']],
        ] as [$endpoint, $email, $extra]) {
            $token = $this->postJson($endpoint, ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => $email, 'birthday' => '1995-04-02', ...$extra])
                ->assertStatus(202)
                ->json('data.registration_token');

            Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Verifications')
                && $request['To'] === $email
                && $request['Channel'] === 'email'
                && json_decode($request['ChannelConfiguration'], true)['substitutions']['first_name'] === 'Ana');

            $this->postJson('/api/client/v1/auth/verify-otp', ['email' => $email, 'code' => $this->codes[$email]])
                ->assertOk()
                ->assertJsonPath('data.password_required', true);

            $this->postJson('/api/client/v1/auth/complete-registration', [
                'email' => $email, 'registration_token' => $token,
                'password' => 'chosenpass123', 'password_confirmation' => 'chosenpass123',
            ])->assertCreated();

            $this->assertTrue(User::query()->where('email', $email)->firstOrFail()->hasVerifiedEmail());
        }

        // Twilio sent the emails; the app's own mailer sent none.
        Notification::assertNothingSent();
    }

    public function test_a_wrong_code_counts_against_the_attempts(): void
    {
        $this->postJson('/api/client/v1/auth/register', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'typo@gmail.com', 'birthday' => '1995-04-02'])
            ->assertStatus(202);

        $wrong = $this->codes['typo@gmail.com'] === '111111' ? '222222' : '111111';
        $this->postJson('/api/client/v1/auth/verify-otp', ['email' => 'typo@gmail.com', 'code' => $wrong])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Incorrect verification code. 4 attempts remaining.');
    }

    public function test_the_code_can_be_resent_again_and_again_after_each_minute(): void
    {
        $this->postJson('/api/client/v1/auth/register', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'again@gmail.com', 'birthday' => '1995-04-02'])
            ->assertStatus(202);

        // Inside the minute: refused, nothing sent.
        $this->postJson('/api/client/v1/auth/resend-otp', ['email' => 'again@gmail.com'])->assertStatus(429);

        foreach ([1, 2, 3] as $_) {
            $this->travel(61)->seconds();
            $this->postJson('/api/client/v1/auth/resend-otp', ['email' => 'again@gmail.com'])->assertStatus(202);
        }

        $this->assertSame(4, $this->sends('again@gmail.com'));
        // The latest code is the one that works.
        $this->postJson('/api/client/v1/auth/verify-otp', ['email' => 'again@gmail.com', 'code' => $this->codes['again@gmail.com']])
            ->assertOk();
    }

    public function test_forgot_password_sends_a_code_each_time_and_the_code_resets_the_password(): void
    {
        $user = User::factory()->create([
            'email' => 'forgot@gmail.com', 'user_type' => 'customer', 'status' => 'active',
            'email_verified_at' => now(), 'password' => Hash::make('oldpassword1'),
        ]);

        foreach ([1, 2] as $_) {
            $this->postJson('/api/client/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
            $this->travel(61)->seconds();
        }
        $this->assertSame(2, $this->sends($user->email));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Verifications')
            && json_decode($request['ChannelConfiguration'], true)['substitutions']['purpose'] === 'reset your SkillServe password');

        $resetToken = $this->postJson('/api/client/v1/auth/verify-reset-code', ['email' => $user->email, 'code' => $this->codes[$user->email]])
            ->assertOk()
            ->json('data.reset_token');

        $this->postJson('/api/client/v1/auth/reset-password', [
            'token' => $resetToken, 'email' => $user->email,
            'password' => 'newpassword99', 'password_confirmation' => 'newpassword99',
        ])->assertOk();

        $this->postJson('/api/client/v1/auth/login', ['email' => $user->email, 'password' => 'newpassword99'])->assertOk();
        Notification::assertNotSentTo($user, ClientEmailOtpNotification::class);
    }

    public function test_twilio_refusing_more_sends_is_explained_to_the_user(): void
    {
        $this->sendError = ['status' => 429, 'code' => 60203, 'message' => 'Max send attempts reached'];

        $this->postJson('/api/client/v1/auth/register', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'limit@gmail.com', 'birthday' => '1995-04-02'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many codes were sent to this address. Please wait 10 minutes and try again.');
    }

    public function test_missing_twilio_settings_fail_clearly_instead_of_pretending_to_send(): void
    {
        config(['services.twilio.verify_service_sid' => null]);

        $this->postJson('/api/client/v1/auth/register', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'nosetup@gmail.com', 'birthday' => '1995-04-02'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'We could not send your verification code. Please try again in a moment.');

        $this->assertDatabaseMissing('pending_registrations', ['email' => 'nosetup@gmail.com']);
    }
}
