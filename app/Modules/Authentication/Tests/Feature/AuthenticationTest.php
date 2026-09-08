<?php

namespace App\Modules\Authentication\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ad-hoc route to prove the Spatie permission middleware is wired.
        Route::middleware(['auth:sanctum', 'permission:view reports'])
            ->get('/api/_permission-test', fn () => response()->json(['ok' => true]));
    }

    private function createUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('password123'),
        ], $attributes));
    }

    public function test_successful_login_returns_standard_envelope_with_token(): void
    {
        Role::findOrCreate('admin');
        $user = $this->createUser(['email' => 'admin@skillserve.test']);
        $user->assignRole('admin');

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@skillserve.test',
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'token_type',
                    'expires_at',
                    'user' => ['id', 'name', 'email', 'roles', 'permissions'],
                ],
                'errors',
                'meta',
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_platform_users_cannot_login_to_the_admin_system(): void
    {
        $this->createUser(['email' => 'customer@skillserve.test']);

        $this->postJson('/api/auth/login', [
            'email' => 'customer@skillserve.test',
            'password' => 'password123',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'administrator_login_failed',
            'log_name' => 'authentication',
        ]);
    }

    public function test_login_with_invalid_credentials_returns_401(): void
    {
        $this->createUser(['email' => 'admin@skillserve.test']);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@skillserve.test',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_login_validation_failures_return_422_envelope(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'not-an-email',
            'password' => '',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    public function test_me_returns_authenticated_user_with_roles_and_permissions(): void
    {
        Permission::findOrCreate('view reports');
        $role = Role::create(['name' => 'admin']);
        $role->givePermissionTo('view reports');

        $user = $this->createUser();
        $user->assignRole('admin');

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles', ['admin'])
            ->assertJsonPath('data.permissions', ['view reports']);
    }

    public function test_me_without_token_returns_401(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_logout_invalidates_the_token(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        // Forget the cached guards first: the Sanctum guard caches its user on
        // the container, which survives across requests inside a single test.
        Auth::forgetGuards();

        // The same token must no longer authenticate.
        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_change_password_updates_the_password(): void
    {
        Role::findOrCreate('admin');
        $user = $this->createUser();
        $user->assignRole('admin');
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'password123',
                'password' => 'newpassword456',
                'password_confirmation' => 'newpassword456',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // Old password must fail, new password must work.
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(401);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'newpassword456',
        ])->assertOk();
    }

    public function test_change_password_revokes_other_sessions_but_keeps_the_current_session(): void
    {
        Role::findOrCreate('admin');
        $user = $this->createUser();
        $user->assignRole('admin');
        $currentToken = $user->createToken('current')->plainTextToken;
        $secondaryToken = $user->createToken('secondary')->plainTextToken;

        $this->withToken($currentToken)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'password123',
                'password' => 'newpassword456',
                'password_confirmation' => 'newpassword456',
            ])
            ->assertOk();

        Auth::forgetGuards();

        $this->withToken($currentToken)
            ->getJson('/api/auth/me')
            ->assertOk();

        Auth::forgetGuards();

        $this->withToken($secondaryToken)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_change_password_with_wrong_current_password_returns_422(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'wrong-current',
                'password' => 'newpassword456',
                'password_confirmation' => 'newpassword456',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['current_password']]);
    }

    public function test_permission_middleware_allows_and_denies(): void
    {
        Permission::findOrCreate('view reports');
        $role = Role::create(['name' => 'reporter']);
        $role->givePermissionTo('view reports');

        $allowed = $this->createUser();
        $allowed->assignRole('reporter');

        $this->withToken($allowed->createToken('test')->plainTextToken)
            ->getJson('/api/_permission-test')
            ->assertOk();

        $denied = $this->createUser();

        // See above — reset cached guards so the denied request is not treated
        // as the previously authenticated user.
        Auth::forgetGuards();

        $this->withToken($denied->createToken('test')->plainTextToken)
            ->getJson('/api/_permission-test')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
