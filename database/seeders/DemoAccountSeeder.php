<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Enums\AccountRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one known customer and one known provider account so the mobile app
 * can be signed into straight after seeding (active, email verified, and a
 * verified provider profile).
 *
 * Override via DEMO_CUSTOMER_EMAIL / DEMO_PROVIDER_EMAIL / DEMO_ACCOUNT_PASSWORD.
 * In production the accounts are skipped unless a non-default
 * DEMO_ACCOUNT_PASSWORD is set, so no publicly known credentials go live.
 *
 * Idempotent: existing accounts and profiles are left untouched.
 */
class DemoAccountSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DEFAULT_PASSWORD = 'SkillServe#2026';

    public function run(): void
    {
        $password = (string) env('DEMO_ACCOUNT_PASSWORD', self::DEFAULT_PASSWORD);

        if (app()->environment('production') && ($password === '' || $password === self::DEFAULT_PASSWORD)) {
            $this->command?->warn('Skipping demo accounts: set a non-default DEMO_ACCOUNT_PASSWORD to seed them in production.');

            return;
        }

        $actor = User::query()->whereHas('roles')->first();

        $customer = $this->account(
            (string) env('DEMO_CUSTOMER_EMAIL', 'customer@skillserve.test'),
            'Carla',
            'Santos',
            AccountRole::Customer,
            $password,
            $actor,
        );

        $provider = $this->account(
            (string) env('DEMO_PROVIDER_EMAIL', 'provider@skillserve.test'),
            'Paolo',
            'Reyes',
            AccountRole::Provider,
            $password,
            $actor,
        );

        ProviderProfile::query()->firstOrCreate(
            ['user_id' => $provider->id],
            [
                'business_name' => 'Reyes Home Repair',
                'bio' => 'Demo provider account for testing the mobile app: general home repair, plumbing and electrical work.',
                'specialization' => 'Home Repair',
                'experience_years' => 6,
                'hourly_rate' => 500.00,
                'location' => 'Quezon City, Metro Manila',
                'latitude' => 14.6760,
                'longitude' => 121.0437,
                'skills' => ['Plumbing', 'Electrical Repair', 'Carpentry'],
                'certifications' => ['TESDA NC II'],
                'languages' => ['English', 'Filipino'],
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $actor?->id,
            ],
        );

        $this->command?->info("Demo accounts: {$customer->email} (customer), {$provider->email} (provider).");
    }

    private function account(string $email, string $firstName, string $lastName, AccountRole $role, string $password, ?User $actor): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => "{$firstName} {$lastName}",
                'role_id' => $role->value,
                'password' => Hash::make($password),
                'phone' => '+63 917 000 0000',
                'status' => 'active',
                'email_verified_at' => now(),
                'created_by' => $actor?->id,
            ],
        );
    }
}
