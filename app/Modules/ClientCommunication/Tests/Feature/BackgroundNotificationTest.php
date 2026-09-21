<?php

namespace App\Modules\ClientCommunication\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\ClientCommunication\Services\BackgroundNotificationService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Closed-app notifications without Firebase: the app's background task polls
 * with a narrow token. Also covers the chat presence channel, which must
 * refuse that token.
 */
class BackgroundNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_gets_a_background_token_that_reads_pending_notifications(): void
    {
        $client = $this->customer();
        $booking = $this->booking($client);

        $background = $this->withToken($this->sessionToken($client))
            ->postJson('/api/client/v1/notifications/background-token')
            ->assertCreated()
            ->json('data.token');

        $client->notify(new BookingStatusNotification($booking, 'confirmed', 'Booking accepted', 'Your provider accepted.'));

        $this->app['auth']->forgetGuards();
        $this->withToken($background)
            ->getJson('/api/client/v1/notifications/background')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Booking accepted')
            ->assertJsonPath('data.0.data.booking_id', $booking->id);
    }

    public function test_pending_honours_after_and_skips_read_and_old_notifications(): void
    {
        $client = $this->customer();
        $booking = $this->booking($client);
        $token = $this->backgroundToken($client);

        $this->travel(-2)->days();
        $client->notify(new BookingStatusNotification($booking, 'confirmed', 'Old', 'Old.'));
        $this->travelBack();
        $client->notify(new BookingStatusNotification($booking, 'active', 'Read', 'Read.'));
        $client->unreadNotifications()->latest()->first()->markAsRead();
        $this->travel(1)->minutes();
        $client->notify(new BookingStatusNotification($booking, 'completed', 'Newest', 'Newest.'));

        // No `after`: only the last day, unread only.
        $this->withToken($token)->getJson('/api/client/v1/notifications/background')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Newest');

        $this->withToken($token)
            ->getJson('/api/client/v1/notifications/background?after='.urlencode(now()->addMinute()->toIso8601String()))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($token)->getJson('/api/client/v1/notifications/background?after=yesterday-ish')
            ->assertStatus(422);
    }

    public function test_the_background_token_can_do_nothing_else(): void
    {
        $client = $this->customer();
        $booking = $this->booking($client);
        $token = $this->backgroundToken($client);

        $this->withToken($token)->getJson('/api/client/v1/notifications')->assertForbidden();
        $this->withToken($token)->getJson('/api/client/v1/bookings')->assertForbidden();
        $this->withToken($token)->getJson("/api/client/v1/bookings/{$booking->id}/messages")->assertForbidden();
        $this->withToken($token)->getJson('/api/client/v1/auth/me')->assertForbidden();
        $this->withToken($token)->postJson('/api/client/v1/notifications/background-token')->assertForbidden();

        // …and a full session token is not a background token.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->sessionToken($client))->getJson('/api/client/v1/notifications/background')->assertForbidden();
    }

    public function test_the_provider_background_token_cannot_read_the_chat_either(): void
    {
        $client = $this->customer();
        $booking = $this->booking($client);
        $providerUser = User::query()->findOrFail(ProviderProfile::query()->findOrFail($booking->provider_id)->user_id);

        $this->withToken($this->backgroundToken($providerUser))
            ->getJson("/api/client/v1/bookings/{$booking->id}/messages")
            ->assertForbidden();
    }

    public function test_signing_out_ends_the_background_token_and_each_account_keeps_at_most_five(): void
    {
        $client = $this->customer();
        foreach (range(1, 6) as $_) {
            app(BackgroundNotificationService::class)->issueToken($client);
        }
        $this->assertSame(5, $client->tokens()->where('name', BackgroundNotificationService::TOKEN_NAME)->count());

        $background = $this->backgroundToken($client);
        $this->withToken($this->sessionToken($client))->postJson('/api/client/v1/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($background)->getJson('/api/client/v1/notifications/background')->assertUnauthorized();
    }

    public function test_a_suspended_account_gets_nothing(): void
    {
        $client = $this->customer();
        $token = $this->backgroundToken($client);
        $client->update(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/client/v1/notifications/background')->assertForbidden();
    }

    public function test_only_the_two_participants_join_the_chat_presence_channel(): void
    {
        $client = $this->customer();
        $booking = $this->booking($client);
        $providerUser = User::query()->findOrFail(ProviderProfile::query()->findOrFail($booking->provider_id)->user_id);
        $stranger = $this->customer();
        $join = Broadcast::driver()->getChannels()->get('booking-chat.{booking}');

        $member = $join($this->asSessionUser($client), $booking);
        $this->assertSame(['id' => $client->id, 'name' => $client->name], $member);
        $this->assertIsArray($join($this->asSessionUser($providerUser), $booking));
        $this->assertFalse($join($this->asSessionUser($stranger), $booking));

        // The background token neither joins the chat nor the user channel.
        $client->withAccessToken($client->createToken('bg', [config('client-auth.background_ability')])->accessToken);
        $this->assertFalse($join($client, $booking));
        $userChannel = Broadcast::driver()->getChannels()->get('App.Models.User.{id}');
        $this->assertFalse($userChannel($client, $client->id));
        $this->assertTrue($userChannel($this->asSessionUser($client), $client->id));
    }

    private function asSessionUser(User $user): User
    {
        return $user->withAccessToken($user->createToken('s', [config('client-auth.access_ability')])->accessToken);
    }

    private function sessionToken(User $user): string
    {
        return $user->createToken('session', [config('client-auth.access_ability')])->plainTextToken;
    }

    private function backgroundToken(User $user): string
    {
        return app(BackgroundNotificationService::class)->issueToken($user)['token'];
    }

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'client.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    private function booking(User $client): Booking
    {
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Background Provider',
            'verification_status' => 'verified',
        ]);
        $category = ServiceCategory::create(['name' => 'Background '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Aircon Cleaning',
            'price' => 100,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
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
}
