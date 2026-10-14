<?php

namespace App\Modules\Notifications\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Notifications\Events\AdminNotificationCreated;
use App\Modules\Notifications\Notifications\AdminAlertNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Events\ServiceCreated;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Seeded in every real environment; the policies check them.
        foreach (['manage services', 'manage bookings', 'manage reports', 'manage support', 'view providers', 'view identity verifications', 'view commissions'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    public function test_a_new_support_request_alerts_only_administrators_who_handle_support(): void
    {
        Event::fake([AdminNotificationCreated::class]);
        $support = $this->administrator(['view support']);
        $moderator = $this->administrator(['view reports']);
        $client = $this->customer();

        $this->withToken($this->clientToken($client))
            ->postJson('/api/client/v1/support/tickets', ['subject' => 'Charged twice', 'description' => 'My booking shows two charges.'])
            ->assertCreated();

        $alert = $support->notifications()->sole();
        $this->assertSame(AdminAlertNotification::class, $alert->type);
        $this->assertSame('support_ticket', $alert->data['kind']);
        $this->assertSame('/admin/support', $alert->data['link']);
        $this->assertCount(0, $moderator->notifications);
        // App accounts never receive admin alerts.
        $this->assertCount(0, $client->notifications()->where('type', AdminAlertNotification::class)->get());
        // Pushed live to the administrator's open pages.
        Event::assertDispatched(AdminNotificationCreated::class, fn ($event) => $event->userId === $support->id);
    }

    public function test_reports_and_disputes_alert_their_moderators(): void
    {
        $moderator = $this->administrator(['view reports']);
        $bookings = $this->administrator(['view bookings']);
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider, 'completed');
        $token = $this->clientToken($client);

        $this->withToken($token)->postJson('/api/client/v1/reports', [
            'booking_id' => $booking->id,
            'reason' => 'no_show',
            'description' => 'The provider never arrived at all.',
        ])->assertCreated();

        Auth::forgetGuards();
        $this->withToken($token)->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", [
            'reason' => 'The work was never finished.',
        ])->assertOk();

        $this->assertSame(['report'], $moderator->notifications()->get()->pluck('data.kind')->all());
        $dispute = $bookings->notifications()->sole();
        $this->assertSame('dispute', $dispute->data['kind']);
        $this->assertSame($booking->id, $dispute->data['booking_id']);
    }

    public function test_only_a_providers_service_waiting_for_approval_alerts_service_reviewers(): void
    {
        $reviewer = $this->administrator(['view services']);
        [$provider, $providerUser] = $this->provider();

        $pending = $this->service($provider, 'pending');
        event(new ServiceCreated(service: $pending, actor: $providerUser, data: []));
        // Already live: nothing to review.
        event(new ServiceCreated(service: $this->service($provider, 'approved'), actor: $providerUser, data: []));
        // Added by an administrator: nothing to review either.
        event(new ServiceCreated(service: $this->service($provider, 'pending'), actor: $this->administrator(['create services']), data: []));

        $alert = $reviewer->notifications()->sole();
        $this->assertSame('service_review', $alert->data['kind']);
        $this->assertSame($pending->id, $alert->data['service_id']);
    }

    public function test_a_reply_on_an_assigned_ticket_alerts_only_its_assignee(): void
    {
        $assignee = $this->administrator(['view support']);
        $other = $this->administrator(['view support']);
        $client = $this->customer();
        $ticket = SupportTicket::create([
            'ticket_number' => 'SUP-'.Str::upper(Str::random(10)),
            'requester_id' => $client->id,
            'subject' => 'Refund',
            'description' => 'Where is my refund?',
            'category' => 'payment',
            'priority' => 'normal',
            'status' => 'in_progress',
            'assigned_to' => $assignee->id,
        ]);

        $this->withToken($this->clientToken($client))
            ->postJson("/api/client/v1/support/tickets/{$ticket->id}/replies", ['body' => 'Any news?'])
            ->assertSuccessful();

        $this->assertStringContainsString('Any news?', $assignee->notifications()->sole()->data['message']);
        $this->assertCount(0, $other->notifications);
    }

    public function test_an_administrator_reads_and_clears_only_their_own_inbox(): void
    {
        $admin = $this->administrator(['view support']);
        $other = $this->administrator(['view support']);
        $admin->notify($this->alert('First'));
        $admin->notify($this->alert('Second'));
        $other->notify($this->alert('Not yours'));
        $token = $admin->createToken('admin')->plainTextToken;

        $this->withToken($token)->getJson('/api/inbox')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.kind', 'support_ticket')
            ->assertJsonPath('data.0.link', '/admin/support')
            ->assertJsonPath('meta.pagination.total', 2);
        $this->withToken($token)->getJson('/api/inbox/unread-count')->assertJsonPath('data.unread_count', 2);

        $first = $admin->notifications()->where('data->title', 'First')->value('id');
        $this->withToken($token)->patchJson("/api/inbox/{$first}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $first);
        $this->withToken($token)->getJson('/api/inbox?status=unread')->assertJsonCount(1, 'data');

        // Another administrator's alert is not found, and stays unread.
        $theirs = $other->notifications()->value('id');
        $this->withToken($token)->patchJson("/api/inbox/{$theirs}/read")->assertNotFound();
        $this->assertNull($other->notifications()->value('read_at'));

        $this->withToken($token)->postJson('/api/inbox/read-all')->assertJsonPath('data.updated_count', 1);
        $this->withToken($token)->getJson('/api/inbox/unread-count')->assertJsonPath('data.unread_count', 0);
        $this->withToken($token)->getJson('/api/inbox?status=bogus')->assertUnprocessable();
    }

    public function test_the_inbox_is_for_administrators_only(): void
    {
        $this->getJson('/api/inbox')->assertUnauthorized();

        $this->withToken($this->clientToken($this->customer()))
            ->getJson('/api/inbox')
            ->assertForbidden();
    }

    private function alert(string $title): AdminAlertNotification
    {
        return new AdminAlertNotification(AdminAlertNotification::SUPPORT_TICKET, $title, 'Message', '/admin/support', ['ticket_id' => 1]);
    }

    /** @param  array<int, string>  $permissions */
    private function administrator(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $role = Role::findOrCreate('inbox-'.Str::random(8));
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function customer(): User
    {
        return User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
    }

    private function clientToken(User $client): string
    {
        return $client->createToken('client-test', ['client:auth'])->plainTextToken;
    }

    /** @return array{0: ProviderProfile, 1: User} */
    private function provider(): array
    {
        $user = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Test Provider',
            'verification_status' => 'verified',
        ]);

        return [$provider, $user];
    }

    private function service(ProviderProfile $provider, string $approval): Service
    {
        $category = ServiceCategory::create(['name' => 'Inbox '.Str::random(6), 'status' => 'enabled']);

        return Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Aircon cleaning',
            'price' => 100,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => $approval,
            'is_hidden' => false,
        ]);
    }

    private function booking(User $client, ProviderProfile $provider, string $status): Booking
    {
        return Booking::create([
            'service_id' => $this->service($provider, 'approved')->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(12)),
            'status' => $status,
            'payment_status' => 'unpaid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'PHP',
            'scheduled_date' => now()->subDay(),
        ]);
    }
}
