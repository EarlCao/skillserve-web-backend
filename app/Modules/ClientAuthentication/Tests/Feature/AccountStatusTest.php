<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Users\Notifications\AccountStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * M 1.6 / M 2.4 / M 9.6 / M 15.4: a restricted account is told why, and
 * account actions reach the user.
 */
class AccountStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_suspended_account_is_told_why_at_login(): void
    {
        $this->customer(['status' => 'suspended', 'suspension_reason' => 'Repeated no-shows.', 'suspended_at' => now()]);

        $this->postJson('/api/client/v1/auth/login', ['email' => 'maria@skillserve.test', 'password' => 'Secret#2026'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is suspended.')
            ->assertJsonPath('meta.account.status', 'suspended')
            ->assertJsonPath('meta.account.reason', 'Repeated no-shows.')
            ->assertJsonPath('meta.account.until', null);
    }

    public function test_a_temporary_ban_gives_its_end_date_and_a_permanent_one_says_so(): void
    {
        $until = now()->addDays(7)->startOfMinute();
        $this->customer(['status' => 'banned', 'ban_reason' => 'Harassment.', 'banned_at' => now(), 'banned_until' => $until]);

        $this->postJson('/api/client/v1/auth/login', ['email' => 'maria@skillserve.test', 'password' => 'Secret#2026'])
            ->assertForbidden()
            ->assertJsonPath('meta.account.status', 'banned')
            ->assertJsonPath('meta.account.reason', 'Harassment.')
            ->assertJsonPath('meta.account.until', $until->toIso8601String());

        User::query()->where('email', 'maria@skillserve.test')->update(['banned_until' => null]);

        $this->postJson('/api/client/v1/auth/login', ['email' => 'maria@skillserve.test', 'password' => 'Secret#2026'])
            ->assertJsonPath('message', 'Your account has been permanently banned.');
    }

    public function test_an_account_suspended_mid_session_gets_the_details_on_its_next_request(): void
    {
        $user = $this->customer();
        $token = $user->createToken('s', ['client:auth'])->plainTextToken;
        $user->update(['status' => 'suspended', 'suspension_reason' => 'Fraud review.', 'suspended_at' => now()]);

        $this->withToken($token)->getJson('/api/client/v1/bookings')
            ->assertForbidden()
            ->assertJsonPath('meta.account.status', 'suspended')
            ->assertJsonPath('meta.account.reason', 'Fraud review.');
    }

    public function test_me_carries_the_account_status_and_a_provider_suspension(): void
    {
        $user = $this->customer(['user_type' => 'provider']);
        ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Maria Cleaning',
            'verification_status' => 'verified',
            'suspended_at' => now(),
            'suspension_reason' => 'Pending investigation.',
        ]);

        $this->withToken($user->createToken('s', ['client:auth'])->plainTextToken)
            ->getJson('/api/client/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.account.status', 'active')
            ->assertJsonPath('data.provider.suspended', true)
            ->assertJsonPath('data.provider.suspension_reason', 'Pending investigation.');
    }

    public function test_a_warning_from_a_report_reaches_the_user_and_their_history(): void
    {
        Notification::fake();
        foreach (['view reports', 'manage reports', 'manage moderation', 'manage users', 'view users'] as $name) {
            Permission::findOrCreate($name);
        }
        $role = Role::create(['name' => 'moderator-'.Str::random(5)]);
        $role->syncPermissions(['view reports', 'manage moderation', 'view users']);
        $moderator = User::factory()->create(['status' => 'active']);
        $moderator->assignRole($role);
        $token = $moderator->createToken('m')->plainTextToken;

        $user = $this->customer();
        $report = Report::create([
            'reportable_type' => $user->getMorphClass(),
            'reportable_id' => $user->id,
            'reporter_id' => User::factory()->create(['user_type' => 'customer'])->id,
            'reason' => 'harassment',
            'description' => 'Rude messages.',
            'status' => 'pending',
        ]);

        $this->withToken($token)
            ->patchJson("/api/reports/{$report->id}/action", ['action' => 'warning', 'reason' => 'Keep messages respectful.'])
            ->assertOk();

        $this->assertSame('active', $user->fresh()->status);
        Notification::assertSentTo($user, AccountStatusNotification::class, fn ($n) => $n->toArray($user)['action'] === 'warned'
            && $n->toArray($user)['reason'] === 'Keep messages respectful.');
        $this->assertDatabaseHas('activity_log', ['subject_id' => $user->id, 'description' => 'user_warned']);

        $this->withToken($token)->getJson("/api/users/{$user->id}/moderation-history")
            ->assertOk()
            ->assertJsonPath('data.0.event', 'user_warned');
    }

    public function test_suspension_and_reactivation_notify_the_user(): void
    {
        Notification::fake();
        foreach (['manage users', 'suspend users', 'activate users'] as $name) {
            Permission::findOrCreate($name);
        }
        $role = Role::create(['name' => 'user-admin-'.Str::random(5)]);
        $role->syncPermissions(['suspend users', 'activate users']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);
        $token = $admin->createToken('a')->plainTextToken;
        $user = $this->customer();

        $this->withToken($token)->patchJson("/api/users/{$user->id}/suspend", ['reason' => 'Spam listings.'])->assertOk();
        Notification::assertSentTo($user, AccountStatusNotification::class, fn ($n) => $n->toArray($user)['action'] === 'suspended');

        $this->withToken($token)->patchJson("/api/users/{$user->id}/activate")->assertOk();
        Notification::assertSentTo($user, AccountStatusNotification::class, fn ($n) => $n->toArray($user)['action'] === 'activated');
        Notification::assertNotSentTo($admin, AccountStatusNotification::class);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'maria@skillserve.test',
            'password' => Hash::make('Secret#2026'),
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }
}
