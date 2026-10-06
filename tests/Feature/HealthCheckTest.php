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
}
