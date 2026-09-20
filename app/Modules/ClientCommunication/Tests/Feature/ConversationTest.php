<?php

namespace App\Modules\ClientCommunication\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Events\ClientMessageCreated;
use App\Modules\ClientCommunication\Notifications\BookingMessageNotification;
use App\Modules\ClientPreferences\Services\ClientPreferenceService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_inbox_lists_only_threads_the_account_takes_part_in(): void
    {
        $client = $this->customer();
        $stranger = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider);

        $this->message($booking, $client, $providerUser, 'Are you free on Friday?');

        // A booking the account has nothing to do with never appears.
        [$otherProvider, $otherProviderUser] = $this->provider();
        $otherBooking = $this->booking($stranger, $otherProvider);
        $this->message($otherBooking, $stranger, $otherProviderUser, 'Not your thread.');

        $response = $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/conversations')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.booking_id', $booking->id)
            ->assertJsonPath('data.0.booking_number', $booking->booking_number)
            ->assertJsonPath('data.0.service_title', 'Messaging service')
            // The customer sees the provider, by business name.
            ->assertJsonPath('data.0.counterpart.id', $providerUser->id)
            ->assertJsonPath('data.0.counterpart.name', 'Test Provider')
            ->assertJsonPath('data.0.last_message.content', 'Are you free on Friday?')
            ->assertJsonPath('data.0.last_message.is_mine', true);

        $this->assertSame(0, $response->json('data.0.unread_count'));

        // The provider sees the same thread, from the other side.
        Auth::forgetGuards();
        $this->withToken($providerUser->createToken('provider', ['client:auth'])->plainTextToken)
            ->getJson('/api/client/v1/conversations')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.counterpart.id', $client->id)
            ->assertJsonPath('data.0.counterpart.name', $client->name)
            ->assertJsonPath('data.0.last_message.is_mine', false)
            ->assertJsonPath('data.0.unread_count', 1);
    }

    public function test_a_booking_nobody_has_written_on_is_not_a_conversation(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $this->booking($client, $provider);

        $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/conversations')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_threads_are_ordered_by_their_newest_message_and_removed_ones_are_hidden(): void
    {
        $client = $this->customer();
        [$providerA, $providerUserA] = $this->provider();
        [$providerB, $providerUserB] = $this->provider();
        $older = $this->booking($client, $providerA);
        $newer = $this->booking($client, $providerB);

        $this->message($older, $client, $providerUserA, 'Sent first.', now()->subHours(2)->toDateTimeString());
        $this->message($newer, $client, $providerUserB, 'Sent later.', now()->subMinute()->toDateTimeString());

        $data = $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/conversations')
            ->assertOk()
            ->json('data');

        $this->assertSame([$newer->id, $older->id], array_column($data, 'booking_id'));

        // A moderator-removed message leaves the thread without content, so it
        // drops out of the inbox instead of previewing hidden text.
        Message::query()->where('booking_id', $newer->id)->update(['status' => 'removed']);

        Auth::forgetGuards();
        $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/conversations')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.booking_id', $older->id);
    }

    public function test_the_unread_count_covers_every_thread_and_clears_when_a_thread_is_opened(): void
    {
        $client = $this->customer();
        [$providerA, $providerUserA] = $this->provider();
        [$providerB, $providerUserB] = $this->provider();
        $first = $this->booking($client, $providerA);
        $second = $this->booking($client, $providerB);

        $this->message($first, $providerUserA, $client, 'One.');
        $this->message($first, $providerUserA, $client, 'Two.');
        $this->message($second, $providerUserB, $client, 'Three.');

        $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/conversations/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3);

        // Opening one thread clears only that thread.
        Auth::forgetGuards();
        $this->withToken($this->clientToken($client))
            ->getJson("/api/client/v1/bookings/{$first->id}/messages")
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/conversations/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_reading_the_inbox_does_not_mark_anything_read(): void
    {
        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider);
        $message = $this->message($booking, $providerUser, $client, 'Still unread.');

        $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);

        $this->assertNull($message->fresh()->read_at);
    }

    public function test_sending_a_message_pushes_it_to_the_receiver_in_realtime_and_notifies_them(): void
    {
        Event::fake([ClientMessageCreated::class]);
        Notification::fake();

        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider);

        $this->withToken($this->clientToken($client))
            ->withHeader('Idempotency-Key', 'realtime-1')
            ->postJson("/api/client/v1/bookings/{$booking->id}/messages", ['content' => 'On my way.'])
            ->assertCreated();

        Event::assertDispatched(
            ClientMessageCreated::class,
            fn (ClientMessageCreated $event) => $event->receiverId === $providerUser->id
                && $event->bookingId === $booking->id
                && $event->message['content'] === 'On my way.',
        );
        Notification::assertSentTo($providerUser, BookingMessageNotification::class);
        Notification::assertNotSentTo($client, BookingMessageNotification::class);

        // A replayed key writes nothing, so it must deliver nothing twice.
        Auth::forgetGuards();
        $this->withToken($this->clientToken($client))
            ->withHeader('Idempotency-Key', 'realtime-1')
            ->postJson("/api/client/v1/bookings/{$booking->id}/messages", ['content' => 'On my way.'])
            ->assertOk();

        Event::assertDispatchedTimes(ClientMessageCreated::class, 1);
        Notification::assertSentToTimes($providerUser, BookingMessageNotification::class, 1);
    }

    public function test_muting_message_notifications_silences_the_alert_but_not_the_realtime_push(): void
    {
        Event::fake([ClientMessageCreated::class]);
        Notification::fake();

        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider);

        app(ClientPreferenceService::class)->update($providerUser, ['message_notifications' => false]);

        $this->withToken($this->clientToken($client))
            ->postJson("/api/client/v1/bookings/{$booking->id}/messages", ['content' => 'Still delivered.'])
            ->assertCreated();

        // The chat the provider may be looking at still updates…
        Event::assertDispatched(ClientMessageCreated::class);
        // …while the banner they switched off stays off.
        Notification::assertNothingSentTo($providerUser);
    }

    public function test_the_inbox_rejects_administrators_and_unauthenticated_callers(): void
    {
        $this->getJson('/api/client/v1/conversations')->assertUnauthorized();
        $this->getJson('/api/client/v1/conversations/unread-count')->assertUnauthorized();

        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 'active']);

        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->getJson('/api/client/v1/conversations')
            ->assertForbidden();
    }

    public function test_the_inbox_validates_its_pagination(): void
    {
        $this->withToken($this->clientToken($this->customer()))
            ->getJson('/api/client/v1/conversations?per_page=0')
            ->assertStatus(422);
    }

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'customer.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    private function clientToken(User $client): string
    {
        return $client->createToken('client-test', ['client:auth'])->plainTextToken;
    }

    /**
     * @return array{0: ProviderProfile, 1: User}
     */
    private function provider(): array
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(8).'@skillserve.test',
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

    private function message(Booking $booking, User $sender, User $receiver, string $content, ?string $at = null): Message
    {
        $message = Message::query()->create([
            'booking_id' => $booking->id,
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'content' => $content,
            'status' => 'active',
        ]);

        // created_at is not fillable, so back-dating has to be forced — without
        // it every message in a test shares one timestamp and ordering is
        // undefined.
        if ($at !== null) {
            $message->forceFill(['created_at' => $at])->save();
        }

        return $message;
    }
}
