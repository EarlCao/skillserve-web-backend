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
     * Set SEED_MODE=admin-only to seed only the super-admin and admin
     * accounts (otherwise empty database). The default mode seeds
     * the full demo dataset.
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
