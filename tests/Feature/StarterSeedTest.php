<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Shared\Enums\AccountRole;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultCommissionTierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** SEED_MODE=starter: the super-admin plus the default setup, and no demo data. */
class StarterSeedTest extends TestCase
{
    use RefreshDatabase;

    private function seedStarter(): void
    {
        putenv('SEED_MODE=starter');
        $_ENV['SEED_MODE'] = $_SERVER['SEED_MODE'] = 'starter';

        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            putenv('SEED_MODE');
            unset($_ENV['SEED_MODE'], $_SERVER['SEED_MODE']);
        }
    }

    public function test_starter_mode_seeds_roles_and_categories_but_no_demo_users(): void
    {
        $this->seedStarter();
        $this->seedStarter(); // Re-running changes nothing.

        $this->assertTrue(Role::query()->where('name', 'super-admin')->exists());
        $this->assertGreaterThan(0, ServiceCategory::query()->count());
        $this->assertSame(
            ServiceCategory::query()->count(),
            ServiceCategory::query()->distinct()->count('name'),
        );
        $this->assertDatabaseMissing('users', ['email' => 'customer@skillserve.test']);
    }

    public function test_starter_mode_seeds_the_standard_commission_tiers_and_the_badges(): void
    {
        $this->seedStarter();
        $this->seedStarter(); // Re-running adds no duplicates.

        $admin = User::query()->where('role_id', AccountRole::SuperAdmin->value)->sole();
        $tiers = CommissionTier::query()->active()->orderBy('min_amount')->get();

        $this->assertSame([5.0, 10.0, 15.0, 20.0], $tiers->map(fn ($tier) => (float) $tier->percentage)->all());
        $this->assertSame(0.0, (float) $tiers->first()->min_amount);
        $this->assertNull($tiers->last()->max_amount);
        $this->assertTrue($tiers->every(fn ($tier) => $tier->created_by === $admin->id));

        $this->assertSame(
            ['experienced', 'fast-responder', 'top-rated', 'trusted-provider'],
            ProviderBadge::query()->orderBy('slug')->pluck('slug')->all(),
        );

        // Only the super-admin: the default setup creates no people.
        $this->assertSame(1, User::query()->count());
    }

    public function test_an_administrators_own_tiers_are_never_replaced(): void
    {
        CommissionTier::create(['name' => 'Everything', 'min_amount' => 0, 'max_amount' => null, 'percentage' => 12, 'is_active' => true]);

        $this->seed(DefaultCommissionTierSeeder::class);

        $this->assertSame(1, CommissionTier::query()->count());
        $this->assertSame(12.0, (float) CommissionTier::query()->sole()->percentage);
    }
}
