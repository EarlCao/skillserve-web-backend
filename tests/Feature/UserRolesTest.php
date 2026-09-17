<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Enums\AccountRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function roleManagerToken(): string
    {
        Permission::findOrCreate('manage administrators');
        Role::findOrCreate('admin')->givePermissionTo('manage administrators');
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('admin');

        return $user->createToken('test')->plainTextToken;
    }

    public function test_roles_table_has_the_four_fixed_roles(): void
    {
        $this->assertSame(
            [1 => 'super-admin', 2 => 'admin', 3 => 'provider', 4 => 'customer'],
            DB::table('roles')->whereIn('id', [1, 2, 3, 4])->orderBy('id')->pluck('name', 'id')->all(),
        );
        $this->assertTrue(Schema::hasColumn('users', 'role_id'));
        $this->assertFalse(Schema::hasColumn('users', 'user_type'));
    }

    public function test_users_reference_an_existing_role(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Broken', 'email' => 'broken@skillserve.test', 'password' => Hash::make('x'),
            'status' => 'active', 'role_id' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_new_accounts_default_to_customer_and_user_type_is_derived(): void
    {
        $customer = User::factory()->create();
        $provider = User::factory()->create(['user_type' => 'provider']);

        $this->assertSame(AccountRole::Customer->value, $customer->fresh()->role_id);
        $this->assertSame('customer', $customer->fresh()->user_type);
        $this->assertSame(AccountRole::Provider->value, $provider->fresh()->role_id);
        $this->assertSame('provider', $provider->fresh()->role->name);
        $this->assertFalse($provider->fresh()->roles()->exists());
    }

    public function test_assigning_staff_roles_updates_role_id_and_back(): void
    {
        Role::findOrCreate('super-admin');
        $custom = Role::findOrCreate('service-manager');
        $user = User::factory()->create();

        $user->assignRole('service-manager');
        $this->assertSame($custom->id, $user->fresh()->role_id);
        $this->assertSame('admin', $user->fresh()->user_type);

        $user->assignRole('super-admin');
        $this->assertSame(AccountRole::SuperAdmin->value, $user->fresh()->role_id);

        $user->syncRoles([]);
        $this->assertSame(AccountRole::Customer->value, $user->fresh()->role_id);

        // Setting role_id directly assigns the matching staff role.
        $user->update(['role_id' => AccountRole::Admin->value]);
        $this->assertSame(['admin'], $user->fresh()->getRoleNames()->all());

        // Turning a staff account into a provider clears its staff roles.
        $user->update(['role_id' => AccountRole::Provider->value]);
        $this->assertFalse($user->fresh()->roles()->exists());
    }

    public function test_deleting_a_custom_role_moves_its_users_to_customer(): void
    {
        $token = $this->roleManagerToken();
        $role = Role::findOrCreate('temporary-staff');
        $user = User::factory()->create();
        $user->assignRole('temporary-staff');

        $this->withToken($token)->deleteJson("/api/roles/{$role->id}")->assertSuccessful();

        $this->assertSame(AccountRole::Customer->value, $user->fresh()->role_id);
    }

    public function test_fixed_roles_are_protected_and_account_types_hidden_from_role_management(): void
    {
        $token = $this->roleManagerToken();

        foreach ([AccountRole::Admin, AccountRole::Provider, AccountRole::Customer] as $role) {
            $this->withToken($token)->deleteJson("/api/roles/{$role->value}")->assertStatus(422);
        }
        $this->withToken($token)->putJson('/api/roles/2', ['name' => 'renamed-admin'])->assertStatus(422);
        $this->withToken($token)->putJson('/api/roles/3', ['description' => 'x'])->assertStatus(422);

        $names = collect($this->withToken($token)->getJson('/api/roles?per_page=100')->assertOk()->json('data'))->pluck('name');
        $this->assertNotContains('provider', $names);
        $this->assertNotContains('customer', $names);

        $this->withToken($token)
            ->postJson('/api/administrators', [
                'first_name' => 'Not', 'last_name' => 'Staff', 'email' => 'not.staff@skillserve.test',
                'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'customer',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role']]);
    }

    public function test_client_api_returns_role_for_mobile_accounts(): void
    {
        $user = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        ProviderProfile::create(['user_id' => $user->id, 'business_name' => 'Role Check', 'verification_status' => 'verified']);

        $this->withToken($user->createToken('client', ['client:auth'])->plainTextToken)
            ->getJson('/api/client/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role_id', 3)
            ->assertJsonPath('data.role_name', 'provider')
            ->assertJsonPath('data.user_type', 'provider');
    }
}
