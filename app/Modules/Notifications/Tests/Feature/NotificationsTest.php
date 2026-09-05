<?php

namespace App\Modules\Notifications\Tests\Feature;

use App\Models\User;
use App\Modules\Notifications\Jobs\SendAnnouncementJob;
use App\Modules\Notifications\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_authorized_administrator_can_create_an_immediate_announcement(): void
    {
        [$actor, $token] = $this->actingAdministrator(['view notifications', 'send announcements']);
        $this->createRecipient('customer@example.test', 'customer');
        $this->createRecipient('provider@example.test', 'provider');
        Queue::fake();

        $this->withToken($token)
            ->postJson('/api/notifications/announcements', [
                'title' => 'Service update',
                'message' => 'The platform has been updated.',
                'target' => 'all',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Service update')
            ->assertJsonPath('data.recipient_count', 2);

        Queue::assertPushed(SendAnnouncementJob::class);
        $this->assertSame($actor->id, Announcement::query()->value('created_by'));
    }

    public function test_targeting_permission_is_required_for_specific_audiences(): void
    {
        [, $token] = $this->actingAdministrator(['send announcements']);

        $this->withToken($token)
            ->postJson('/api/notifications/announcements', [
                'title' => 'Clients only',
                'message' => 'A targeted message.',
                'target' => 'customers',
            ])
            ->assertForbidden();
    }

    public function test_announcement_validation_requires_recipients_for_selected_target(): void
    {
        [, $token] = $this->actingAdministrator(['send announcements', 'target notifications']);

        $this->withToken($token)
            ->postJson('/api/notifications/announcements', [
                'title' => 'Selected users',
                'message' => 'A targeted message.',
                'target' => 'selected',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['recipient_ids']]);
    }

    private function actingAdministrator(array $permissions): array
    {
        foreach (array_merge(['manage administrators'], $permissions) as $permission) {
            Permission::findOrCreate($permission);
        }

        $role = Role::findOrCreate('notification-manager-'.Str::random(8));
        $role->syncPermissions($permissions);
        $user = User::factory()->create([
            'email' => 'admin-'.Str::random(8).'@skillserve.test',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function createRecipient(string $email, string $type): User
    {
        return User::factory()->create([
            'email' => $email,
            'status' => 'active',
            'user_type' => $type,
        ]);
    }
}
