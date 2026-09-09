<?php

namespace App\Modules\ClientCommunication\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Notifications\SupportTicketResolvedNotification;
use App\Modules\ClientCommunication\Notifications\SupportTicketResponseNotification;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Notifications\AnnouncementNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ClientCommunicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_inbox_is_scoped_and_read_state_is_mutable(): void
    {
        $client = $this->customer();
        $other = $this->customer();
        $announcement = Announcement::create([
            'created_by' => $client->id,
            'title' => 'Important update',
            'message' => 'Your service is ready.',
            'target' => 'selected',
            'recipient_ids' => [$client->id],
            'recipient_count' => 1,
            'status' => 'sent',
        ]);
        $client->notify(new AnnouncementNotification($announcement));
        $other->notify(new AnnouncementNotification($announcement));
        $notification = $client->notifications()->firstOrFail();

        $token = $this->clientToken($client);
        $this->withToken($token)
            ->getJson('/api/client/v1/notifications?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.title', 'Important update')
            ->assertJsonPath('data.0.read_at', null);

        $this->withToken($token)
            ->getJson('/api/client/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->withToken($token)
            ->patchJson("/api/client/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read_at', fn ($value) => $value !== null);

        $this->withToken($token)
            ->getJson('/api/client/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $client->notify(new AnnouncementNotification($announcement));
        $this->withToken($token)
            ->postJson('/api/client/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->withToken($token)
            ->patchJson('/api/client/v1/notifications/'.$other->notifications()->firstOrFail()->id.'/read')
            ->assertNotFound();
    }

    public function test_client_support_tickets_are_owned_and_replies_use_existing_support_action(): void
    {
        $client = $this->customer();
        $other = $this->customer();
        $otherTicket = $this->ticket($other);
        $token = $this->clientToken($client);

        $created = $this->withToken($token)
            ->postJson('/api/client/v1/support/tickets', [
                'subject' => 'Cannot update my address',
                'description' => 'The address form does not save.',
                'category' => 'account',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonMissingPath('data.assigned_to');

        $ticketId = $created->json('data.id');

        $this->withToken($token)
            ->getJson('/api/client/v1/support/tickets')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $ticketId);

        $this->withToken($token)
            ->getJson("/api/client/v1/support/tickets/{$otherTicket->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->postJson("/api/client/v1/support/tickets/{$ticketId}/replies", ['body' => 'I can provide a screenshot.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.messages.0.body', 'I can provide a screenshot.')
            ->assertJsonMissingPath('data.messages.0.author.email');

        $this->withToken($token)
            ->postJson('/api/client/v1/support/tickets', ['description' => 'Missing subject'])
            ->assertUnprocessable();
    }

    public function test_support_replies_and_resolutions_notify_the_requesting_client(): void
    {
        Notification::fake();
        $client = $this->customer();
        $ticket = $this->withToken($this->clientToken($client))
            ->postJson('/api/client/v1/support/tickets', [
                'subject' => 'Notification test',
                'description' => 'Please notify me about support changes.',
            ])
            ->assertCreated()
            ->json('data');

        Permission::findOrCreate('respond to support tickets', 'web');
        Permission::findOrCreate('resolve support tickets', 'web');
        $role = Role::findOrCreate('support-agent', 'web');
        $role->syncPermissions(['respond to support tickets', 'resolve support tickets']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $agent = $this->customer(['email' => 'support-agent@example.com']);
        $agent->assignRole($role);
        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/support/tickets/{$ticket['id']}/responses", ['body' => 'We are reviewing this now.'])
            ->assertOk();

        $responseNotification = null;
        Notification::assertSentTo($client, SupportTicketResponseNotification::class, function (SupportTicketResponseNotification $notification) use (&$responseNotification): bool {
            $responseNotification = $notification;

            return true;
        });
        $this->assertSame(['database'], $responseNotification->via($client));
        $this->assertSame($ticket['id'], $responseNotification->toArray($client)['ticket_id']);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/support/tickets/{$ticket['id']}/resolve", ['resolution_note' => 'The issue has been resolved.'])
            ->assertOk();

        $resolvedNotification = null;
        Notification::assertSentTo($client, SupportTicketResolvedNotification::class, function (SupportTicketResolvedNotification $notification) use (&$resolvedNotification): bool {
            $resolvedNotification = $notification;

            return true;
        });
        $this->assertSame(['database'], $resolvedNotification->via($client));
        $this->assertSame($ticket['id'], $resolvedNotification->toArray($client)['ticket_id']);
    }

    public function test_booking_messages_require_participation_support_idempotency_and_mark_received_messages_read(): void
    {
        $client = $this->customer();
        $other = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider);
        $clientToken = $this->clientToken($client);
        $providerToken = $providerUser->createToken('provider-test')->plainTextToken;

        $first = $this->withToken($clientToken)
            ->withHeader('Idempotency-Key', 'message-key-1')
            ->postJson("/api/client/v1/bookings/{$booking->id}/messages", ['content' => 'Hello provider.'])
            ->assertCreated()
            ->assertJsonPath('data.sender.id', $client->id);

        $this->withToken($clientToken)
            ->withHeader('Idempotency-Key', 'message-key-1')
            ->postJson("/api/client/v1/bookings/{$booking->id}/messages", ['content' => 'Hello provider.'])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->withToken($clientToken)
            ->withHeader('Idempotency-Key', 'message-key-1')
            ->postJson("/api/client/v1/bookings/{$booking->id}/messages", ['content' => 'Different content'])
            ->assertStatus(409);

        Auth::forgetGuards();
        $providerMessage = $this->withToken($providerToken)
            ->postJson("/api/client/v1/bookings/{$booking->id}/messages", ['content' => 'Hello client.'])
            ->assertCreated()
            ->assertJsonPath('data.receiver.id', $client->id);

        $this->assertDatabaseHas('messages', ['id' => $first->json('data.id'), 'read_at' => null]);

        Auth::forgetGuards();
        $this->withToken($clientToken)
            ->getJson("/api/client/v1/bookings/{$booking->id}/messages")
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.1.read_at', fn ($value) => $value !== null);

        $this->assertNotNull(Message::query()->findOrFail($providerMessage->json('data.id'))->read_at);

        Auth::forgetGuards();
        $this->withToken($this->clientToken($other))
            ->getJson("/api/client/v1/bookings/{$booking->id}/messages")
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($clientToken)
            ->postJson("/api/client/v1/bookings/{$booking->id}/messages", ['content' => ''])
            ->assertUnprocessable();
    }

    private function customer(): User
    {
        return User::factory()->create([
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    private function clientToken(User $client): string
    {
        return $client->createToken('client-test', ['client:auth'])->plainTextToken;
    }

    private function provider(): array
    {
        $user = User::factory()->create([
            'user_type' => 'provider',
            'status' => 'active',
        ]);
        $provider = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Test Provider',
            'verification_status' => 'verified',
        ]);

        return [$provider, $user];
    }

    private function booking(User $client, ProviderProfile $provider): Booking
    {
        $category = ServiceCategory::create(['name' => 'Communication '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Messaging service',
            'price' => 100,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
            'is_hidden' => false,
        ]);

        return Booking::create([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(12)),
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'PHP',
            'scheduled_date' => now()->addDay(),
        ]);
    }

    private function ticket(User $requester): SupportTicket
    {
        return SupportTicket::create([
            'ticket_number' => 'SUP-'.Str::upper(Str::random(12)),
            'requester_id' => $requester->id,
            'subject' => 'Existing ticket',
            'description' => 'Not owned by the current client.',
            'category' => 'general',
            'priority' => 'normal',
            'status' => 'open',
        ]);
    }
}
