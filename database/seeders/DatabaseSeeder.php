<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
     * - `demo` (default) — the full demo dataset. Never in production.
     */
    public function run(): void
    {
        $mode = env('SEED_MODE', 'demo');

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
