<?php

namespace Tests\Feature;

use App\Modules\ServiceCategories\Models\ServiceCategory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** SEED_MODE=starter: admins plus a usable catalog, and no demo data. */
class StarterSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_mode_seeds_roles_and_categories_but_no_demo_users(): void
    {
        putenv('SEED_MODE=starter');
        $_ENV['SEED_MODE'] = $_SERVER['SEED_MODE'] = 'starter';

        try {
            $this->seed(DatabaseSeeder::class);
            $this->seed(DatabaseSeeder::class); // Re-running changes nothing.
        } finally {
            putenv('SEED_MODE');
            unset($_ENV['SEED_MODE'], $_SERVER['SEED_MODE']);
        }

        $this->assertTrue(Role::query()->where('name', 'super-admin')->exists());
        $this->assertGreaterThan(0, ServiceCategory::query()->count());
        $this->assertSame(
            ServiceCategory::query()->count(),
            ServiceCategory::query()->distinct()->count('name'),
        );
        $this->assertDatabaseMissing('users', ['email' => 'customer@skillserve.test']);
    }
}
