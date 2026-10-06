<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
