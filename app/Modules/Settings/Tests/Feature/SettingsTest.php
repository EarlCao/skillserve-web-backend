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
