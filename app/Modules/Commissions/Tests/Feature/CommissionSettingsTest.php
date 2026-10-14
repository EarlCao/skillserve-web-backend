<?php

namespace App\Modules\Commissions\Tests\Feature;

use App\Models\User;
use App\Modules\Settings\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The commission rules, edited on the Commission page. */
class CommissionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_commission_managers_read_and_change_the_rules(): void
    {
        $token = $this->token(['manage commissions']);

        $this->withToken($token)->getJson('/api/commission-settings')
            ->assertOk()
            ->assertJsonPath('data.commission_rate', 10)
            ->assertJsonPath('data.commission_block_min_amount', 0)
            ->assertJsonPath('data.commission_block_after_days', 0);

        $this->withToken($token)
            ->putJson('/api/commission-settings', ['commission_rate' => 12.5, 'commission_block_min_amount' => 500, 'commission_block_after_days' => 7])
            ->assertOk()
            ->assertJsonPath('data.commission_block_after_days', 7);

        // The same stored values the rules read.
        $settings = app(SettingsService::class);
        $this->assertEquals(12.5, $settings->value('marketplace', 'commission_rate'));
        $this->assertEquals(500, $settings->value('marketplace', 'commission_block_min_amount'));
        $this->assertEquals(7, $settings->value('marketplace', 'commission_block_after_days'));

        $this->withToken($token)
            ->putJson('/api/commission-settings', ['commission_rate' => 120, 'commission_block_after_days' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commission_rate', 'commission_block_after_days']);
        // Other settings cannot be slipped in through this page.
        $this->withToken($token)
            ->putJson('/api/commission-settings', ['commission_rate' => 11, 'service_approval_required' => false])
            ->assertOk();
        $this->assertTrue((bool) $settings->value('marketplace', 'service_approval_required'));
    }

    public function test_viewers_read_but_cannot_change_them(): void
    {
        $token = $this->token(['view commissions']);
        $settingsOnly = $this->token(['manage settings']);

        $this->withToken($token)->getJson('/api/commission-settings')->assertOk();
        $this->withToken($token)->putJson('/api/commission-settings', ['commission_rate' => 5])->assertForbidden();

        // System Settings rights alone no longer reach the commission rules.
        Auth::forgetGuards();
        $this->withToken($settingsOnly)->getJson('/api/commission-settings')->assertForbidden();
    }

    /** @param  array<int, string>  $permissions */
    private function token(array $permissions): string
    {
        foreach (['manage commissions', 'view commissions', 'manage settings', ...$permissions] as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::findOrCreate('commission-'.Str::random(6));
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user->createToken('admin')->plainTextToken;
    }
}
