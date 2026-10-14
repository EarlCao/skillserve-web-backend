<?php

namespace App\Modules\ClientCommunication\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Events\ClientMessageCreated;
use App\Modules\ClientCommunication\Models\Inquiry;
use App\Modules\ClientCommunication\Notifications\BookingMessageNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Chat before booking. */
class InquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_starts_one_conversation_per_listed_provider(): void
    {
        $customer = $this->customer();
        [$provider] = $this->provider();
        $token = $this->clientToken($customer);

        $first = $this->withToken($token)
            ->postJson('/api/client/v1/inquiries', ['provider_id' => $provider->id])
            ->assertOk()
            ->assertJsonPath('data.kind', 'inquiry')
            ->assertJsonPath('data.booking_id', null)
            ->assertJsonPath('data.counterpart.provider_id', $provider->id)
            ->assertJsonPath('data.counterpart.name', 'Test Provider')
            ->json('data.inquiry_id');

        // Asking again reopens the same conversation.
        $this->withToken($token)
            ->postJson('/api/client/v1/inquiries', ['provider_id' => $provider->id])
            ->assertOk()
            ->assertJsonPath('data.inquiry_id', $first);
        $this->assertSame(1, Inquiry::query()->count());

        // A provider the marketplace does not list cannot be messaged.
        [$unverified] = $this->provider(['verification_status' => 'pending']);
        $this->withToken($token)
            ->postJson('/api/client/v1/inquiries', ['provider_id' => $unverified->id])
            ->assertNotFound();
        $this->withToken($token)->postJson('/api/client/v1/inquiries', [])->assertUnprocessable();
    }

    public function test_only_customers_start_conversations(): void
    {
        [$provider] = $this->provider();
        [, $otherProviderUser] = $this->provider();

        $this->withToken($this->clientToken($otherProviderUser))
            ->postJson('/api/client/v1/inquiries', ['provider_id' => $provider->id])
            ->assertForbidden();
        Auth::forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/client/v1/inquiries', ['provider_id' => $provider->id])->assertUnauthorized();
    }

    public function test_both_parties_write_and_read_and_strangers_cannot(): void
    {
        Event::fake([ClientMessageCreated::class]);
        Notification::fake();
        $customer = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $inquiry = Inquiry::create(['customer_id' => $customer->id, 'provider_profile_id' => $provider->id]);

        $this->withToken($this->clientToken($customer))
            ->postJson("/api/client/v1/inquiries/{$inquiry->id}/messages", ['content' => 'Do you fix split-type aircons?'], ['Idempotency-Key' => 'k-1'])
            ->assertCreated()
            ->assertJsonPath('data.inquiry_id', $inquiry->id)
            ->assertJsonPath('data.booking_id', null);
        // A retried send returns the stored message.
        Auth::forgetGuards();
        $this->withToken($this->clientToken($customer))
            ->postJson("/api/client/v1/inquiries/{$inquiry->id}/messages", ['content' => 'Do you fix split-type aircons?'], ['Idempotency-Key' => 'k-1'])
            ->assertOk();
        $this->assertSame(1, Message::query()->where('inquiry_id', $inquiry->id)->count());

        // Delivered live and as a notification that opens this conversation.
        Event::assertDispatched(ClientMessageCreated::class, fn ($event) => $event->receiverId === $providerUser->id
            && $event->inquiryId === $inquiry->id && $event->bookingId === null);
        Notification::assertSentTo($providerUser, BookingMessageNotification::class,
            fn ($notification) => $notification->toArray($providerUser)['inquiry_id'] === $inquiry->id);

        // The provider reads it (marking it read) and replies.
        Auth::forgetGuards();
        $providerToken = $this->clientToken($providerUser);
        $this->withToken($providerToken)
            ->getJson("/api/client/v1/inquiries/{$inquiry->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', 'Do you fix split-type aircons?');
        $this->assertNotNull(Message::query()->where('inquiry_id', $inquiry->id)->value('read_at'));
        $this->withToken($providerToken)
            ->postJson("/api/client/v1/inquiries/{$inquiry->id}/messages", ['content' => 'Yes, ₱800 per unit.'])
            ->assertCreated();

        // Nobody else can read or write.
        Auth::forgetGuards();
        $stranger = $this->clientToken($this->customer());
        $this->withToken($stranger)->getJson("/api/client/v1/inquiries/{$inquiry->id}/messages")->assertForbidden();
        $this->withToken($stranger)->postJson("/api/client/v1/inquiries/{$inquiry->id}/messages", ['content' => 'Hi'])->assertForbidden();
    }

    public function test_a_photo_in_a_conversation_is_for_its_two_parties_only(): void
    {
        Storage::fake('message_images');
        $customer = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $inquiry = Inquiry::create(['customer_id' => $customer->id, 'provider_profile_id' => $provider->id]);

        $imageUrl = $this->withToken($this->clientToken($customer))
            ->post("/api/client/v1/inquiries/{$inquiry->id}/messages", [
                'image' => UploadedFile::fake()->image('unit.jpg', 800, 600),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.image_url');
        $this->assertStringContainsString("/inquiries/{$inquiry->id}/messages/", $imageUrl);
        $path = parse_url($imageUrl, PHP_URL_PATH);

        Auth::forgetGuards();
        $this->withToken($this->clientToken($providerUser))->get($path)->assertOk();
        Auth::forgetGuards();
        $this->withToken($this->clientToken($this->customer()))->get($path, ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_the_inbox_adds_conversations_before_booking_only_when_asked(): void
    {
        $customer = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($customer, $provider);
        $inquiry = Inquiry::create(['customer_id' => $customer->id, 'provider_profile_id' => $provider->id]);
        $this->message(['booking_id' => $booking->id], $customer, $providerUser, 'About Friday', '2026-10-10 09:00:00');
        $this->message(['inquiry_id' => $inquiry->id], $customer, $providerUser, 'Do you do weekends?', '2026-10-11 09:00:00');
        // A conversation nobody wrote in yet is not listed.
        [$quietProvider] = $this->provider();
        Inquiry::create(['customer_id' => $customer->id, 'provider_profile_id' => $quietProvider->id]);

        $token = $this->clientToken($customer);
        // Older app builds only know booking threads.
        $this->withToken($token)->getJson('/api/client/v1/conversations')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'booking');

        $this->withToken($token)->getJson('/api/client/v1/conversations?include_inquiries=1')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.kind', 'inquiry')
            ->assertJsonPath('data.0.inquiry_id', $inquiry->id)
            ->assertJsonPath('data.0.last_message.content', 'Do you do weekends?')
            ->assertJsonPath('data.1.kind', 'booking')
            ->assertJsonPath('meta.pagination.total', 2);

        // The provider sees the customer, with the message unread.
        Auth::forgetGuards();
        $this->withToken($this->clientToken($providerUser))->getJson('/api/client/v1/conversations?include_inquiries=1&per_page=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counterpart.name', $customer->name)
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('meta.pagination.last_page', 2);
    }

    public function test_a_received_message_can_be_reported(): void
    {
        $customer = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $inquiry = Inquiry::create(['customer_id' => $customer->id, 'provider_profile_id' => $provider->id]);
        $message = $this->message(['inquiry_id' => $inquiry->id], $providerUser, $customer, 'Pay me outside the app.');

        $this->withToken($this->clientToken($customer))
            ->postJson('/api/client/v1/reports', [
                'message_id' => $message->id,
                'reason' => 'harassment',
                'description' => 'Asked me to pay outside the app.',
            ])
            ->assertCreated();
    }

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'customer.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    private function clientToken(User $user): string
    {
        return $user->createToken('client-test', ['client:auth'])->plainTextToken;
    }

    /** @return array{0: ProviderProfile, 1: User} */
    private function provider(array $profile = []): array
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
            ...$profile,
        ]);

        return [$provider, $user];
    }

    private function booking(User $client, ProviderProfile $provider): Booking
    {
        $category = ServiceCategory::create(['name' => 'Inquiry '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Aircon cleaning',
            'price' => 800,
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
            'total_price' => 800,
            'service_price' => 800,
            'platform_fee' => 80,
            'currency' => 'PHP',
            'scheduled_date' => now()->addDay(),
        ]);
    }

    /** @param  array<string, int>  $thread  ['booking_id' => …] or ['inquiry_id' => …] */
    private function message(array $thread, User $sender, User $receiver, string $content, ?string $at = null): Message
    {
        $message = Message::query()->create([
            ...$thread,
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'content' => $content,
            'status' => 'active',
        ]);

        if ($at !== null) {
            $message->forceFill(['created_at' => $at])->save();
        }

        return $message;
    }
}
