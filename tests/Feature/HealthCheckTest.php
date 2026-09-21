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
}
