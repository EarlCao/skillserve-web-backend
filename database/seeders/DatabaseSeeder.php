<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Platform data every site starts with, beyond the admin accounts. */
    private const DEFAULT_SETUP = [
        ServiceCategorySeeder::class,
        DefaultCommissionTierSeeder::class,
        ProviderBadgeSeeder::class,
    ];

    /**
     * Seed the application's database.
     *
     * SEED_MODE:
     * - `admin-only` — roles, permissions and the super-admin account only.
     * - `starter`    — the default setup: the same plus the default service
     *                  categories and subcategories, the Standard commission
     *                  tiers and the provider badges. No sample people, so a
     *                  fresh site is usable at once. Idempotent: existing
     *                  categories, tiers and badges are kept. The default in
     *                  production.
     * - `demo` — the full demo dataset. The default outside production.
     *
     * Production refuses `demo` and seeds `starter` instead: the demo accounts
     * are active with the password "password", and an unset variable once
     * seeded them onto the live site.
     */
    public function run(): void
    {
        $production = app()->environment('production');
        $mode = env('SEED_MODE', $production ? 'starter' : 'demo');

        if ($production && ! in_array($mode, ['admin-only', 'starter'], true)) {
            Log::warning('SEED_MODE is not allowed in production; seeding the default setup (starter) instead.', ['seed_mode' => $mode]);
            $this->command?->warn("SEED_MODE={$mode} is not allowed in production; seeding the default setup (starter) instead.");
            $mode = 'starter';
        }

        // Roles, permissions, and the super-admin and admin accounts are always required.
        $this->call([
            RolePermissionSeeder::class,
        ]);

        if ($mode === 'admin-only') {
            return;
        }

        if ($mode === 'starter') {
            $this->call(self::DEFAULT_SETUP);

            return;
        }

        // Full demo dataset.
        $this->call([
            UsersSeeder::class,
            ServiceCategorySeeder::class,
            ProviderSeeder::class,
            // After ProviderSeeder: it tops up to a fixed profile count.
            DemoAccountSeeder::class,
            ProviderRecognitionSeeder::class,
            DefaultCommissionTierSeeder::class,
            ServiceSeeder::class,
            BookingSeeder::class,
            ReportSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
