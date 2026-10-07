<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The production path end to end: OTP_DRIVER=mail with MAIL_MAILER=brevo-api.
 * Every code — sign-up for customers and providers, every resend, every
 * forgot-password request — leaves through Brevo to the address it is for,
 * and the code in that email is the one that works.
 */
class BrevoOtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** When true, Brevo refuses every message. */
    private bool $refuse = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'client-auth.otp_driver' => 'mail',
            'mail.default' => 'brevo-api',
            'services.brevo.api_key' => 'xkeysib-test',
            'mail.from.address' => 'no-reply@skillserve.example',
        ]);

        Http::fake(['api.brevo.com/*' => function () {
            return $this->refuse
                ? Http::response(['code' => 'unauthorized', 'message' => 'Key not found'], 401)
                : Http::response(['messageId' => '<202610071200.1234@smtp-relay.mailin.fr>'], 201);
        }]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @return array<int, Request> the Brevo requests addressed to $email */
    private function emailsTo(string $email): array
    {
        return Http::recorded(fn (Request $request) => ($request['to'][0]['email'] ?? null) === $email)
            ->map(fn (array $pair) => $pair[0])
            ->values()
            ->all();
    }

    /** The 6-digit code in the latest email to $email. */
    private function latestCode(string $email): string
    {
        $emails = $this->emailsTo($email);
        $this->assertNotEmpty($emails, "No email was sent to {$email}.");
        preg_match('/\b(\d{6})\b/', strip_tags(end($emails)['htmlContent']), $match);

        return $match[1];
    }

    public function test_customers_and_providers_receive_their_code_by_brevo_and_finish_signing_up(): void
    {
        foreach ([
            ['/api/client/v1/auth/register', 'buyer@gmail.com', []],
            ['/api/client/v1/auth/register-provider', 'fixer@yahoo.com', ['specialization' => 'Plumbing']],
        ] as [$endpoint, $email, $extra]) {
            $token = $this->postJson($endpoint, ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => $email, 'birthday' => '1995-04-02', ...$extra])
                ->assertStatus(202)
                ->json('data.registration_token');

            $this->assertSame('Your SkillServe verification code', $this->emailsTo($email)[0]['subject']);

            $this->postJson('/api/client/v1/auth/verify-otp', ['email' => $email, 'code' => $this->latestCode($email)])
                ->assertOk()
                ->assertJsonPath('data.password_required', true);

            $this->postJson('/api/client/v1/auth/complete-registration', [
                'email' => $email, 'registration_token' => $token,
                'password' => 'chosenpass123', 'password_confirmation' => 'chosenpass123',
            ])->assertCreated();
        }
    }

    public function test_the_code_can_be_resent_many_times_and_the_newest_one_works(): void
    {
        $this->postJson('/api/client/v1/auth/register', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'again@gmail.com', 'birthday' => '1995-04-02'])
            ->assertStatus(202);

        foreach ([1, 2, 3] as $_) {
            $this->travel(61)->seconds();
            $this->postJson('/api/client/v1/auth/resend-otp', ['email' => 'again@gmail.com'])->assertStatus(202);
        }

        $this->assertCount(4, $this->emailsTo('again@gmail.com'));
        $this->postJson('/api/client/v1/auth/verify-otp', ['email' => 'again@gmail.com', 'code' => $this->latestCode('again@gmail.com')])
            ->assertOk();
    }

    public function test_forgot_password_emails_a_code_every_time_it_is_asked_for(): void
    {
        $user = User::factory()->create([
            'email' => 'forgot@gmail.com', 'user_type' => 'provider', 'status' => 'active',
            'email_verified_at' => now(), 'password' => Hash::make('oldpassword1'),
        ]);
        // Every provider account has its profile from sign-up.
        ProviderProfile::create(['user_id' => $user->id, 'specialization' => 'Plumbing', 'verification_status' => 'verified']);

        foreach ([1, 2, 3] as $_) {
            $this->postJson('/api/client/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
            $this->travel(61)->seconds();
        }

        $emails = $this->emailsTo($user->email);
        $this->assertCount(3, $emails);
        $this->assertSame('Your SkillServe password reset code', $emails[0]['subject']);

        $resetToken = $this->postJson('/api/client/v1/auth/verify-reset-code', ['email' => $user->email, 'code' => $this->latestCode($user->email)])
            ->assertOk()
            ->json('data.reset_token');

        $this->postJson('/api/client/v1/auth/reset-password', [
            'token' => $resetToken, 'email' => $user->email,
            'password' => 'newpassword99', 'password_confirmation' => 'newpassword99',
        ])->assertOk();

        $this->postJson('/api/client/v1/auth/login', ['email' => $user->email, 'password' => 'newpassword99'])->assertOk();
    }

    public function test_a_refusal_from_brevo_tells_the_user_and_leaves_no_half_sign_up(): void
    {
        $this->refuse = true;

        $this->postJson('/api/client/v1/auth/register', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'refused@gmail.com', 'birthday' => '1995-04-02'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'We could not send your verification code. Please try again in a moment.');

        $this->assertDatabaseMissing('pending_registrations', ['email' => 'refused@gmail.com']);
    }

    public function test_a_failed_send_can_be_retried_straight_away_and_later_codes_keep_arriving(): void
    {
        $this->postJson('/api/client/v1/auth/register', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'retry@gmail.com', 'birthday' => '1995-04-02'])
            ->assertStatus(202);

        // Brevo refuses one resend: the user is told, and no cooldown is started.
        $this->travel(61)->seconds();
        $this->refuse = true;
        $this->postJson('/api/client/v1/auth/resend-otp', ['email' => 'retry@gmail.com'])->assertStatus(503);

        $this->refuse = false;
        $this->postJson('/api/client/v1/auth/resend-otp', ['email' => 'retry@gmail.com'])->assertStatus(202);

        // And every later request still sends.
        foreach (range(1, 8) as $_) {
            $this->travel(61)->seconds();
            $this->postJson('/api/client/v1/auth/resend-otp', ['email' => 'retry@gmail.com'])->assertStatus(202);
        }

        $this->postJson('/api/client/v1/auth/verify-otp', ['email' => 'retry@gmail.com', 'code' => $this->latestCode('retry@gmail.com')])
            ->assertOk();
    }
}
