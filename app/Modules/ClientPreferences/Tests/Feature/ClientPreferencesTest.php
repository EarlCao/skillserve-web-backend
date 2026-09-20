<?php

namespace App\Modules\ClientPreferences\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Services\ClientSessionService;
use App\Modules\ClientPreferences\Models\ClientPreference;
use App\Modules\ClientPreferences\Services\ClientPreferenceService;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Notifications\AnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('password123'),
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function tokenFor(User $user): string
    {
        return app(ClientSessionService::class)->issue($user)['token'];
    }

    private function preferences(): ClientPreferenceService
    {
        return app(ClientPreferenceService::class);
    }

    private function announcement(): Announcement
    {
        return Announcement::query()->create([
            'created_by' => User::factory()->create()->id,
            'title' => 'Scheduled maintenance',
            'message' => 'The app will be briefly unavailable.',
            'target' => 'all',
            'recipient_ids' => [],
            'recipient_count' => 0,
            'status' => 'sent',
        ]);
    }

    public function test_the_first_read_creates_the_platform_defaults(): void
    {
        $user = $this->customer();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/client/v1/preferences')
            ->assertOk()
            ->assertJsonPath('data.booking_notifications', true)
            ->assertJsonPath('data.service_notifications', true)
            ->assertJsonPath('data.message_notifications', true)
            ->assertJsonPath('data.announcement_notifications', true)
            ->assertJsonPath('data.private_profile', false)
            ->assertJsonPath('data.activity_personalization', true)
            ->assertJsonPath('data.reduce_motion', false)
            ->assertJsonPath('data.theme', 'system');

        $this->assertDatabaseHas('client_preferences', ['user_id' => $user->id]);
    }

    public function test_settings_are_saved_and_follow_the_account_to_another_device(): void
    {
        $user = $this->customer();

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/client/v1/preferences', [
                'announcement_notifications' => false,
                'theme' => 'dark',
            ])
            ->assertOk()
            ->assertJsonPath('data.announcement_notifications', false)
            ->assertJsonPath('data.theme', 'dark')
            // Untouched settings keep their value.
            ->assertJsonPath('data.booking_notifications', true);

        // A second sign-in — a different device — sees the same settings.
        $this->withToken($this->tokenFor($user))
            ->getJson('/api/client/v1/preferences')
            ->assertOk()
            ->assertJsonPath('data.announcement_notifications', false)
            ->assertJsonPath('data.theme', 'dark');
    }

    public function test_repeated_reads_do_not_create_duplicate_rows(): void
    {
        $user = $this->customer();
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/client/v1/preferences')->assertOk();
        $this->withToken($token)->getJson('/api/client/v1/preferences')->assertOk();

        $this->assertSame(1, ClientPreference::query()->where('user_id', $user->id)->count());
    }

    public function test_settings_are_validated(): void
    {
        $user = $this->customer();

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/client/v1/preferences', [
                'theme' => 'neon',
                'private_profile' => 'maybe',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['theme', 'private_profile']);
    }

    public function test_one_account_cannot_read_or_write_another_accounts_settings(): void
    {
        $owner = $this->customer();
        $other = $this->customer();

        $this->preferences()->update($owner, ['theme' => 'dark']);

        // The endpoint takes no identifier, so a different token can only
        // ever reach its own row.
        $this->withToken($this->tokenFor($other))
            ->getJson('/api/client/v1/preferences')
            ->assertOk()
            ->assertJsonPath('data.theme', 'system');

        $this->withToken($this->tokenFor($other))
            ->putJson('/api/client/v1/preferences', ['user_id' => $owner->id, 'theme' => 'light'])
            ->assertOk();

        $this->assertSame('dark', $owner->fresh()->preferences->theme);
    }

    public function test_the_endpoints_reject_anonymous_callers(): void
    {
        $this->getJson('/api/client/v1/preferences')->assertStatus(401);
        $this->putJson('/api/client/v1/preferences', ['theme' => 'dark'])->assertStatus(401);
    }

    public function test_a_muted_category_is_not_delivered_at_all(): void
    {
        $user = $this->customer();
        $announcement = $this->announcement();

        $user->notify(new AnnouncementNotification($announcement));
        $this->assertSame(1, $user->notifications()->count());

        $this->preferences()->update($user, ['announcement_notifications' => false]);

        $user->notify(new AnnouncementNotification($announcement));

        $this->assertSame(
            1,
            $user->fresh()->notifications()->count(),
            'Muting a category must stop delivery, not just hide it in the app.',
        );
    }

    public function test_muting_one_category_leaves_the_others_delivering(): void
    {
        $user = $this->customer();

        $this->preferences()->update($user, ['announcement_notifications' => false]);

        // Muted: withheld entirely.
        $user->notify(new AnnouncementNotification($this->announcement()));
        $this->assertSame(0, $user->fresh()->notifications()->count());

        // Still on: delivered as before.
        $this->preferences()->update($user, ['announcement_notifications' => true]);
        $user->notify(new AnnouncementNotification($this->announcement()));
        $this->assertSame(1, $user->fresh()->notifications()->count());
    }

    public function test_an_administrator_is_never_gated_by_client_preferences(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->forceFill(['role_id' => 2])->save();

        $admin->notify(new AnnouncementNotification($this->announcement()));

        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_an_account_with_no_preferences_row_still_receives_notifications(): void
    {
        $user = $this->customer();
        $announcement = $this->announcement();

        $this->assertDatabaseMissing('client_preferences', ['user_id' => $user->id]);

        $user->notify(new AnnouncementNotification($announcement));

        $this->assertSame(1, $user->notifications()->count());
    }
}
