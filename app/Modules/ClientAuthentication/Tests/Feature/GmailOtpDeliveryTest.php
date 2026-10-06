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
 * The production path end to end: MAIL_MAILER=gmail-api. Sign-up codes for
 * customers and providers, every resend and every forgot-password request go
 * out through Gmail to the address they are for, and the code in the email is
 * the one that works.
 */
class GmailOtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'client-auth.otp_driver' => 'mail',
            'mail.default' => 'gmail-api',
            'services.gmail.client_id' => 'client-id',
            'services.gmail.client_secret' => 'client-secret',
            'services.gmail.refresh_token' => 'refresh-token',
            'mail.from.address' => 'earlcao12345.ec@gmail.com',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
            'gmail.googleapis.com/*' => Http::response(['id' => 'sent']),
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @return array<int, string> the MIME of every email sent to $email, oldest first */
    private function emailsTo(string $email): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'gmail.googleapis.com'))
            ->map(fn (array $pair) => quoted_printable_decode((string) base64_decode(strtr($pair[0]['raw'], '-_', '+/'))))
            ->filter(fn (string $mime) => str_contains($mime, "<{$email}>") || str_contains($mime, "To: {$email}"))
            ->values()
            ->all();
    }

    private function latestCode(string $email): string
    {
        $emails = $this->emailsTo($email);
        $this->assertNotEmpty($emails, "No email was sent to {$email}.");
        preg_match('/<strong>(\d{6})<\/strong>|\*\*(\d{6})\*\*/', end($emails), $match);

        return $match[1] !== '' ? $match[1] : $match[2];
    }

    public function test_customers_and_providers_get_their_code_from_gmail_and_finish_signing_up(): void
    {
        foreach ([
            ['/api/client/v1/auth/register', 'buyer@gmail.com', []],
            ['/api/client/v1/auth/register-provider', 'fixer@yahoo.com', ['specialization' => 'Plumbing']],
        ] as [$endpoint, $email, $extra]) {
            $token = $this->postJson($endpoint, ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => $email, 'birthday' => '1995-04-02', ...$extra])
                ->assertStatus(202)
                ->json('data.registration_token');

            $this->postJson('/api/client/v1/auth/verify-otp', ['email' => $email, 'code' => $this->latestCode($email)])
                ->assertOk()
                ->assertJsonPath('data.password_required', true);

            $this->postJson('/api/client/v1/auth/complete-registration', [
                'email' => $email, 'registration_token' => $token,
                'password' => 'chosenpass123', 'password_confirmation' => 'chosenpass123',
            ])->assertCreated();
        }
    }

    public function test_resends_and_forgot_password_each_send_a_new_email(): void
    {
        $this->postJson('/api/client/v1/auth/register', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'again@gmail.com', 'birthday' => '1995-04-02'])
            ->assertStatus(202);
        foreach ([1, 2] as $_) {
            $this->travel(61)->seconds();
            $this->postJson('/api/client/v1/auth/resend-otp', ['email' => 'again@gmail.com'])->assertStatus(202);
        }
        $this->assertCount(3, $this->emailsTo('again@gmail.com'));

        $user = User::factory()->create([
            'email' => 'forgot@gmail.com', 'user_type' => 'provider', 'status' => 'active',
            'email_verified_at' => now(), 'password' => Hash::make('oldpassword1'),
        ]);
        ProviderProfile::create(['user_id' => $user->id, 'specialization' => 'Plumbing', 'verification_status' => 'verified']);

        foreach ([1, 2] as $_) {
            $this->travel(61)->seconds();
            $this->postJson('/api/client/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
        }
        $this->assertCount(2, $this->emailsTo($user->email));

        $resetToken = $this->postJson('/api/client/v1/auth/verify-reset-code', ['email' => $user->email, 'code' => $this->latestCode($user->email)])
            ->assertOk()
            ->json('data.reset_token');
        $this->postJson('/api/client/v1/auth/reset-password', [
            'token' => $resetToken, 'email' => $user->email,
            'password' => 'newpassword99', 'password_confirmation' => 'newpassword99',
        ])->assertOk();
    }
}
