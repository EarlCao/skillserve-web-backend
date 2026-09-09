<?php

namespace App\Modules\Notifications\Tests\Feature;

use App\Modules\Notifications\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnnouncementsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcements_migration_runs_on_sqlite_and_preserves_the_contract(): void
    {
        $this->assertSame('sqlite', Schema::getConnection()->getDriverName());
        $this->assertTrue(Schema::hasTable('announcements'));
        $this->assertTrue(Schema::hasColumns('announcements', [
            'target', 'recipient_ids', 'recipient_count', 'status', 'scheduled_at', 'sent_at',
        ]));

        Announcement::create([
            'title' => 'Migration test',
            'message' => 'Announcements can be persisted after a SQLite migration.',
            'target' => 'all',
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('announcements', [
            'title' => 'Migration test',
            'target' => 'all',
            'status' => 'sent',
        ]);
    }
}
