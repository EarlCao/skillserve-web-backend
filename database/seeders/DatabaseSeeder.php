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
     * Set SEED_MODE=admin-only to seed only the super-admin account
     * (empty database with one admin user). The default mode seeds
     * the full demo dataset.
     */
    public function run(): void
    {
        $mode = env('SEED_MODE', 'demo');

        // Roles, permissions, and the super-admin account are always required.
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
            ProviderRecognitionSeeder::class,
            ServiceSeeder::class,
            BookingSeeder::class,
            ReportSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
