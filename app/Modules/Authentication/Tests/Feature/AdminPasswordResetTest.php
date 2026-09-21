<?php

namespace App\Modules\Authentication\Tests\Feature;

use App\Models\User;
use App\Modules\Authentication\Notifications\AdminPasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_resets_a_forgotten_password_and_is_signed_out_everywhere(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://admin.skillserve.test']);
        $admin = $this->admin();
        $admin->createToken('old-session');

        $this->postJson('/api/auth/forgot-password', ['email' => $admin->email])->assertOk();

        $token = null;
        Notification::assertSentTo($admin, AdminPasswordResetNotification::class, function ($notification) use (&$token, $admin) {
            $token = $notification->token;
            $url = $notification->toMail($admin)->actionUrl;

            return str_starts_with($url, 'https://admin.skillserve.test/reset-password?');
        });

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'NewSecret#2026',
            'password_confirmation' => 'NewSecret#2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewSecret#2026', $admin->fresh()->password));
        $this->assertSame(0, $admin->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'NewSecret#2026'])->assertOk();
        $this->assertDatabaseHas('activity_log', ['causer_id' => $admin->id]);
    }

    public function test_the_answer_is_the_same_for_unknown_mobile_and_inactive_accounts(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $inactive = $this->admin(['status' => 'suspended']);

        foreach (['nobody@skillserve.test', $customer->email, $inactive->email] as $email) {
            $this->postJson('/api/auth/forgot-password', ['email' => $email])
                ->assertOk()
                ->assertJsonPath('message', 'If that email belongs to an administrator, a reset link is on its way.');
        }

        Notification::assertNothingSent();
    }

    public function test_a_bad_token_or_a_mobile_account_cannot_reset(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $customerToken = Password::broker('admins')->createToken($customer);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'wrong', 'email' => $admin->email,
            'password' => 'NewSecret#2026', 'password_confirmation' => 'NewSecret#2026',
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        $this->postJson('/api/auth/reset-password', [
            'token' => $customerToken, 'email' => $customer->email,
            'password' => 'NewSecret#2026', 'password_confirmation' => 'NewSecret#2026',
        ])->assertStatus(422);

        $this->postJson('/api/auth/reset-password', ['email' => $admin->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token', 'password']);
    }

    private function admin(array $attributes = []): User
    {
        $admin = User::factory()->create(array_merge([
            'password' => Hash::make('OldSecret#2026'),
            'status' => 'active',
        ], $attributes));
        $admin->assignRole(Role::findOrCreate('admin'));

        return $admin;
    }
}
