<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * SEED_MODE:
     * - `admin-only` — roles, permissions and the two admin accounts.
     * - `starter`    — the same plus the default service categories and
     *                  subcategories, so a fresh production site is usable at
     *                  once (idempotent: existing categories are kept).
     * - `demo` — the full demo dataset. The default outside production.
     *
     * Production defaults to `admin-only` and refuses `demo`: the demo accounts
     * are active with the password "password", and an unset variable once
     * seeded them onto the live site.
     */
    public function run(): void
    {
        $production = app()->environment('production');
        $mode = env('SEED_MODE', $production ? 'admin-only' : 'demo');

        if ($production && ! in_array($mode, ['admin-only', 'starter'], true)) {
            Log::warning('SEED_MODE is not allowed in production; seeding admin-only instead.', ['seed_mode' => $mode]);
            $this->command?->warn("SEED_MODE={$mode} is not allowed in production; seeding admin-only instead.");
            $mode = 'admin-only';
        }

        // Roles, permissions, and the super-admin and admin accounts are always required.
        $this->call([
            RolePermissionSeeder::class,
        ]);

        if ($mode === 'admin-only') {
            return;
        }

        if ($mode === 'starter') {
            $this->call([ServiceCategorySeeder::class]);

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
            ServiceSeeder::class,
            BookingSeeder::class,
            ReportSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
