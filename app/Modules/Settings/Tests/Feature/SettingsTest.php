<?php

namespace App\Modules\Settings\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_settings_are_grouped_and_the_timezone_follows_the_server(): void
    {
        config(['app.business_timezone' => 'Asia/Manila']);

        $this->withToken($this->adminToken())
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.general.timezone', 'Asia/Manila')
            ->assertJsonPath('data.general.platform_name', 'SkillServe')
            ->assertJsonPath('meta.read_only', ['general.timezone']);
    }

    public function test_an_admin_updates_settings_but_not_the_read_only_timezone(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson('/api/settings', ['general' => ['platform_name' => 'SkillServe PH'], 'booking' => ['cancellation_window_hours' => 12]])
            ->assertOk()
            ->assertJsonPath('data.general.platform_name', 'SkillServe PH')
            ->assertJsonPath('data.booking.cancellation_window_hours', 12);

        $this->withToken($token)
            ->putJson('/api/settings', ['general' => ['timezone' => 'Europe/London']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('general.timezone');

        $this->assertDatabaseMissing('settings', ['group' => 'general', 'name' => 'timezone']);
    }

    public function test_settings_need_the_manage_settings_permission(): void
    {
        Permission::findOrCreate('manage settings');
        $user = User::factory()->create(['status' => 'active']);

        $this->withToken($user->createToken('t')->plainTextToken)->getJson('/api/settings')->assertForbidden();
    }

    public function test_the_theme_is_public_and_defaults_to_skillserve_green(): void
    {
        $this->getJson('/api/appearance')
            ->assertOk()
            ->assertJsonPath('data', ['primary_color' => '', 'font_family' => 'system', 'font_size' => 'medium']);
    }

    public function test_an_admin_sets_the_theme_and_bad_values_are_refused(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson('/api/settings', ['appearance' => ['primary_color' => 'green', 'font_family' => 'comic_sans', 'font_size' => 'huge']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appearance.primary_color', 'appearance.font_family', 'appearance.font_size']);

        $this->withToken($token)
            ->putJson('/api/settings', ['appearance' => ['primary_color' => '#03833E', 'font_family' => 'poppins', 'font_size' => 'large']])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/appearance')
            ->assertOk()
            ->assertJsonPath('data', ['primary_color' => '#03833E', 'font_family' => 'poppins', 'font_size' => 'large']);
    }

    public function test_an_optional_setting_can_be_cleared(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->putJson('/api/settings', ['appearance' => ['primary_color' => '#03833E'], 'general' => ['support_email' => 'help@skillserve.test']])
            ->assertOk();

        // Empty strings reach the service as null; clearing must still save.
        $this->withToken($token)
            ->putJson('/api/settings', ['appearance' => ['primary_color' => ''], 'general' => ['support_email' => '']])
            ->assertOk()
            ->assertJsonPath('data.appearance.primary_color', '')
            ->assertJsonPath('data.general.support_email', '');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/appearance')->assertJsonPath('data.primary_color', '');
    }

    public function test_the_commission_rules_are_not_part_of_system_settings(): void
    {
        $token = $this->adminToken();

        $marketplace = $this->withToken($token)->getJson('/api/settings')->assertOk()->json('data.marketplace');
        $this->assertArrayHasKey('service_approval_required', $marketplace);
        $this->assertArrayNotHasKey('commission_rate', $marketplace);
        $this->assertArrayNotHasKey('commission_block_min_amount', $marketplace);
        $this->assertArrayNotHasKey('commission_block_after_days', $marketplace);

        // They are changed on the Commission page only.
        $this->withToken($token)
            ->putJson('/api/settings', ['marketplace' => ['commission_rate' => 15]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['marketplace.commission_rate' => ['Change this on the Commission page.']]);
    }

    private function adminToken(): string
    {
        Permission::findOrCreate('manage settings');
        $role = Role::findOrCreate('settings-admin');
        $role->givePermissionTo('manage settings');
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('test')->plainTextToken;
    }
}
