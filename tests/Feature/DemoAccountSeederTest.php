<?php

namespace Tests\Feature;

use App\Models\User;
use App\Shared\Enums\AccountRole;
use Database\Seeders\DemoAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_customer_and_provider_can_sign_in_to_the_mobile_api(): void
    {
        $this->seed([RolePermissionSeeder::class, DemoAccountSeeder::class]);

        $customer = User::query()->where('email', 'customer@skillserve.test')->firstOrFail();
        $provider = User::query()->where('email', 'provider@skillserve.test')->firstOrFail();

        $this->assertSame(AccountRole::Customer->value, (int) $customer->role_id);
        $this->assertSame(AccountRole::Provider->value, (int) $provider->role_id);
        $this->assertSame('verified', $provider->providerProfile->verification_status);

        foreach ([$customer, $provider] as $user) {
            $this->postJson('/api/client/v1/auth/login', [
                'email' => $user->email,
                'password' => 'SkillServe#2026',
            ])->assertOk();
        }
    }

    public function test_reseeding_does_not_duplicate_accounts(): void
    {
        $this->seed([RolePermissionSeeder::class, DemoAccountSeeder::class, DemoAccountSeeder::class]);

        $this->assertSame(1, User::query()->where('email', 'provider@skillserve.test')->count());
        $this->assertSame(1, User::query()->where('email', 'provider@skillserve.test')->first()->providerProfile()->count());
    }

    public function test_production_skips_demo_accounts_with_the_default_password(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->app['env'] = 'production';

        // Run directly: db:seed prompts for confirmation in production.
        $this->app->make(DemoAccountSeeder::class)->run();

        $this->assertFalse(User::query()->where('email', 'customer@skillserve.test')->exists());
    }
}
