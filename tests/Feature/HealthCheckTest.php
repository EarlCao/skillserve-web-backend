<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_reports_the_database_and_the_upload_storage(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('services.database.status', 'up')
            // storage/app holds every upload; in production it is the Render disk.
            ->assertJsonPath('services.storage.status', 'up');
    }

    public function test_a_database_failure_is_reported_without_its_error_text(): void
    {
        $default = config('database.default');
        // A connection that cannot be opened: nothing listens on port 1.
        config([
            'database.connections.unreachable' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1,
                'database' => 'secret_db', 'username' => 'secret_user', 'password' => 'x',
            ],
            'database.default' => 'unreachable',
        ]);

        try {
            $response = $this->getJson('/api/health');
        } finally {
            config(['database.default' => $default]);
        }

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('services.database', ['status' => 'down']);
        $this->assertStringNotContainsString('secret_user', $response->getContent());
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
    }

    public function test_health_says_whether_the_codes_can_be_sent_without_failing_the_check(): void
    {
        config(['client-auth.otp_driver' => 'twilio', 'services.twilio.account_sid' => null]);
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('services.otp', ['status' => 'down', 'driver' => 'twilio']);

        config([
            'services.twilio.account_sid' => 'ACtest',
            'services.twilio.auth_token' => 'secret',
            'services.twilio.verify_service_sid' => 'VAtest',
        ]);
        $response = $this->getJson('/api/health')->assertJsonPath('services.otp.status', 'up');
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_health_checks_the_mailjet_keys_when_the_codes_go_by_email(): void
    {
        config(['client-auth.otp_driver' => 'mail', 'mail.default' => 'mailjet-api', 'services.mailjet.key' => null, 'services.mailjet.secret' => null]);
        $this->getJson('/api/health')->assertJsonPath('services.otp', ['status' => 'down', 'driver' => 'mail', 'mailer' => 'mailjet-api']);

        config(['services.mailjet.key' => 'key', 'services.mailjet.secret' => 'secret']);
        $this->getJson('/api/health')->assertJsonPath('services.otp.status', 'up');

        config(['mail.default' => 'log']);
        $this->getJson('/api/health')->assertJsonPath('services.otp.status', 'down');
    }

    public function test_health_checks_the_brevo_key(): void
    {
        config(['client-auth.otp_driver' => 'mail', 'mail.default' => 'brevo-api', 'services.brevo.api_key' => null]);
        $this->getJson('/api/health')->assertJsonPath('services.otp', ['status' => 'down', 'driver' => 'mail', 'mailer' => 'brevo-api']);

        Http::fake(self::brevo(['plan' => [['type' => 'free', 'creditsType' => 'sendLimit', 'credits' => 280]]], ['requests' => 20, 'delivered' => 19]));
        config(['services.brevo.api_key' => 'xkeysib-secret']);
        $response = $this->getJson('/api/health')->assertJsonPath('services.otp', ['status' => 'up', 'driver' => 'mail', 'mailer' => 'brevo-api']);
        $this->assertStringNotContainsString('xkeysib-secret', $response->getContent());
        Http::assertSent(fn (Request $request) => $request->hasHeader('api-key', 'xkeysib-secret'));
    }

    /** @return array<string, mixed> fakes for Brevo's account and statistics endpoints */
    private static function brevo(array|int $account, array $report = ['requests' => 0, 'delivered' => 0]): array
    {
        return [
            'api.brevo.com/v3/account' => is_int($account)
                ? Http::response(['code' => 'unauthorized', 'message' => 'unrecognised IP address 203.0.113.9'], $account)
                : Http::response($account),
            'api.brevo.com/v3/smtp/statistics/aggregatedReport*' => Http::response($report),
        ];
    }

    /** @return array<string, array{string, array<int, mixed>}> the reason shown, and the arguments for brevo() */
    public static function brevoFailures(): array
    {
        return [
            'key or IP refused' => ['Brevo refused the API key or this server\'s IP address', [401]],
            'allowance used up' => ['sending allowance is used up', [['plan' => [['type' => 'free', 'creditsType' => 'sendLimit', 'credits' => 0]]]]],
            'accepted, none delivered' => ['accepted 12 emails in the last two days and delivered none', [['plan' => []], ['requests' => 12, 'delivered' => 0]]],
        ];
    }

    /** Brevo says yes to every send, so the health check asks Brevo what it really did. */
    #[DataProvider('brevoFailures')]
    public function test_health_reports_what_brevo_would_not_deliver(string $reason, array $brevo): void
    {
        config(['client-auth.otp_driver' => 'mail', 'mail.default' => 'brevo-api', 'services.brevo.api_key' => 'xkeysib-secret']);
        Http::fake(self::brevo(...$brevo));

        $response = $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('services.otp.status', 'down');
        $this->assertStringContainsString($reason, $response->json('services.otp.error'));
        // Brevo's own wording can name the server's IP; it stays in the log.
        $this->assertStringNotContainsString('203.0.113.9', $response->getContent());
    }

    public function test_the_brevo_answer_is_cached_so_the_health_page_does_not_hammer_brevo(): void
    {
        config(['client-auth.otp_driver' => 'mail', 'mail.default' => 'brevo-api', 'services.brevo.api_key' => 'xkeysib-secret']);
        Http::fake(self::brevo(['plan' => []]));

        $this->getJson('/api/health')->assertJsonPath('services.otp.status', 'up');
        $this->getJson('/api/health')->assertJsonPath('services.otp.status', 'up');

        Http::assertSentCount(2);
    }

    public function test_health_checks_the_resend_key(): void
    {
        config(['client-auth.otp_driver' => 'mail', 'mail.default' => 'resend-api', 'services.resend.key' => null]);
        $this->getJson('/api/health')->assertJsonPath('services.otp', ['status' => 'down', 'driver' => 'mail', 'mailer' => 'resend-api']);

        config(['services.resend.key' => 're_secret']);
        $response = $this->getJson('/api/health')->assertJsonPath('services.otp.status', 'up');
        $this->assertStringNotContainsString('re_secret', $response->getContent());
    }

    public function test_health_checks_the_gmail_settings(): void
    {
        config(['client-auth.otp_driver' => 'mail', 'mail.default' => 'gmail-api', 'services.gmail.client_id' => 'id', 'services.gmail.client_secret' => 'secret', 'services.gmail.refresh_token' => null]);
        $this->getJson('/api/health')->assertJsonPath('services.otp', ['status' => 'down', 'driver' => 'mail', 'mailer' => 'gmail-api']);

        config(['services.gmail.refresh_token' => 'token']);
        $this->getJson('/api/health')->assertJsonPath('services.otp.status', 'up');
    }
}
