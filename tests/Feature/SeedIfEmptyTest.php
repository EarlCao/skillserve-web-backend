<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Shared\Enums\AccountRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `db:seed-if-empty` runs on every Render start. It must seed a freshly
 * migrated database, which already holds the fixed roles because the
 * 2026_09_18 migration inserts them, and must skip a database that has its
 * super-admin.
 */
class SeedIfEmptyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SEED_MODE=admin-only');
        $_ENV['SEED_MODE'] = $_SERVER['SEED_MODE'] = 'admin-only';
    }

    protected function tearDown(): void
    {
        putenv('SEED_MODE');
        unset($_ENV['SEED_MODE'], $_SERVER['SEED_MODE']);
        parent::tearDown();
    }

    public function test_a_freshly_migrated_database_gets_its_super_admin(): void
    {
        // Migrations alone leave roles behind, which is what used to fool the check.
        $this->assertTrue(Role::query()->where('name', 'super-admin')->exists());
        $this->assertSame(0, User::query()->count());

        $this->artisan('db:seed-if-empty')->assertSuccessful();

        $admin = User::query()->where('role_id', AccountRole::SuperAdmin->value)->sole();
        $this->assertTrue($admin->hasRole('super-admin'));
    }

    public function test_a_database_with_a_super_admin_is_left_alone(): void
    {
        $this->artisan('db:seed-if-empty')->assertSuccessful();
        $count = User::query()->count();

        $this->artisan('db:seed-if-empty')
            ->expectsOutputToContain('already seeded')
            ->assertSuccessful();

        $this->assertSame($count, User::query()->count());
    }

    /** @return array<string, array{0: string|null}> */
    public static function productionModes(): array
    {
        return ['unset' => [null], 'demo' => ['demo'], 'unknown' => ['everything']];
    }

    #[DataProvider('productionModes')]
    public function test_production_never_seeds_the_demo_dataset(?string $mode): void
    {
        putenv($mode === null ? 'SEED_MODE' : "SEED_MODE={$mode}");
        if ($mode === null) {
            unset($_ENV['SEED_MODE'], $_SERVER['SEED_MODE']);
        } else {
            $_ENV['SEED_MODE'] = $_SERVER['SEED_MODE'] = $mode;
        }
        putenv('ADMIN_PASSWORD=A-strong-production-password-1');
        $_ENV['ADMIN_PASSWORD'] = $_SERVER['ADMIN_PASSWORD'] = 'A-strong-production-password-1';
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed-if-empty')->assertSuccessful();
        } finally {
            $this->app['env'] = 'testing';
            putenv('ADMIN_PASSWORD');
            unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);
        }

        // Only the super-admin: no demo customers, providers or categories.
        $this->assertSame(1, User::query()->count());
        $this->assertSame(AccountRole::SuperAdmin->value, User::query()->sole()->role_id);
        $this->assertSame(0, ServiceCategory::query()->count());
    }
}
