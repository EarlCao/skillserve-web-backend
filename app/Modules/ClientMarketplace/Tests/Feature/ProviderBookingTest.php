<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provider_lists_only_their_own_bookings_with_the_customer_contact_details(): void
    {
        [$provider, , $token] = $this->provider();
        [$other] = $this->provider();
        $service = $this->service($provider);
        $client = $this->client();

        $mine = $this->booking($client, $service, [
            'service_address' => '12 Mabini St, Quezon City',
            'contact_phone' => '09171234567',
        ]);
        $this->booking($client, $this->service($other));

        $response = $this->withToken($token)
            ->getJson('/api/client/v1/provider/bookings')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.client.name', $client->name)
            ->assertJsonPath('data.0.client.phone', '09171234567')
            ->assertJsonPath('data.0.service_address', '12 Mabini St, Quezon City')
            ->assertJsonPath('data.0.service.title', $service->title);

        // The provider gets the customer's contact details, never their account.
        $this->assertArrayNotHasKey('email', $response->json('data.0.client'));
    }

    public function test_the_booking_list_can_be_filtered_by_status_and_rejects_an_unknown_one(): void
    {
        [$provider, , $token] = $this->provider();
        $service = $this->service($provider);
        $client = $this->client();

        $this->booking($client, $service);
        $completed = $this->booking($client, $service, ['status' => 'completed']);

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/bookings?status=completed')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $completed->id);

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/bookings?status=archived')
            ->assertStatus(422);
    }

    public function test_a_provider_walks_a_booking_from_pending_to_completed(): void
    {
        [$provider, , $token] = $this->provider();
        $booking = $this->booking($this->client(), $this->service($provider));

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
        $this->assertNotNull($booking->fresh()->confirmed_at);

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->assertNotNull($booking->fresh()->started_at);

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertNotNull($booking->fresh()->completed_at);
    }

    public function test_each_transition_refuses_a_booking_in_the_wrong_status(): void
    {
        [$provider, , $token] = $this->provider();
        $service = $this->service($provider);
        $client = $this->client();

        $pending = $this->booking($client, $service);
        $confirmed = $this->booking($client, $service, ['status' => 'confirmed']);
        $completed = $this->booking($client, $service, ['status' => 'completed']);

        // A pending booking cannot be started or completed.
        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$pending->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a confirmed booking can be started.');

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$pending->id}/complete")
            ->assertStatus(422);

        // A confirmed booking is past accepting and not yet completable.
        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$confirmed->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a pending booking can be accepted.');

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$confirmed->id}/complete")
            ->assertStatus(422);

        // A finished booking is immovable.
        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$completed->id}/decline")
            ->assertStatus(422);

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame('completed', $completed->fresh()->status);
    }

    public function test_declining_cancels_the_booking_and_records_the_reason(): void
    {
        [$provider, $providerUser, $token] = $this->provider();
        $booking = $this->booking($this->client(), $this->service($provider));

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/decline", [
                'reason' => 'Fully booked that afternoon.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Fully booked that afternoon.');

        $booking->refresh();
        $this->assertSame($providerUser->id, $booking->cancelled_by);
        $this->assertNotNull($booking->cancelled_at);
        // Declining changes booking state only; no refund is claimed.
        $this->assertSame('unpaid', $booking->payment_status);
    }

    public function test_a_decline_reason_is_length_validated(): void
    {
        [$provider, , $token] = $this->provider();
        $booking = $this->booking($this->client(), $this->service($provider));

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/decline", [
                'reason' => str_repeat('a', 1001),
            ])
            ->assertStatus(422);

        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_a_provider_can_cancel_an_accepted_booking_with_a_reason_and_the_customer_is_told(): void
    {
        Notification::fake();

        [$provider, $providerUser, $token] = $this->provider();
        $client = $this->client();
        $booking = $this->booking($client, $this->service($provider), [
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/cancel", [
                'reason' => 'I am unwell and cannot make it.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'I am unwell and cannot make it.');

        $booking->refresh();
        $this->assertSame($providerUser->id, $booking->cancelled_by);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame('unpaid', $booking->payment_status);

        Notification::assertSentTo(
            $client,
            BookingStatusNotification::class,
            fn (BookingStatusNotification $notification) => $notification->toArray($client)['reason'] === 'I am unwell and cannot make it.',
        );
        Notification::assertNotSentTo($providerUser, BookingStatusNotification::class);

        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $booking->id,
            'description' => 'booking_cancelled',
            'causer_id' => $providerUser->id,
        ]);
    }

    public function test_a_provider_cancellation_needs_a_reason(): void
    {
        [$provider, , $token] = $this->provider();
        $booking = $this->booking($this->client(), $this->service($provider), ['status' => 'confirmed']);

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/cancel", ['reason' => 'no'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_a_provider_can_only_cancel_a_confirmed_booking(): void
    {
        [$provider, , $token] = $this->provider();
        $service = $this->service($provider);
        $client = $this->client();

        foreach (['pending', 'active', 'completed', 'cancelled'] as $status) {
            $booking = $this->booking($client, $service, ['status' => $status]);

            $this->withToken($token)
                ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/cancel", ['reason' => 'Cannot make it.'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');

            $this->assertSame($status, $booking->fresh()->status);
        }
    }

    public function test_another_provider_cannot_cancel_the_booking(): void
    {
        [$provider] = $this->provider();
        [, , $intruderToken] = $this->provider();
        $booking = $this->booking($this->client(), $this->service($provider), ['status' => 'confirmed']);

        $this->withToken($intruderToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/cancel", ['reason' => 'Not mine to cancel.'])
            ->assertForbidden();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_another_providers_booking_is_not_readable_or_transitionable(): void
    {
        [$provider] = $this->provider();
        [, , $intruderToken] = $this->provider();
        $booking = $this->booking($this->client(), $this->service($provider));

        $this->withToken($intruderToken)
            ->getJson("/api/client/v1/provider/bookings/{$booking->id}")
            ->assertForbidden();

        $this->withToken($intruderToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/confirm")
            ->assertForbidden();

        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_customer_accounts_cannot_use_the_provider_booking_endpoints(): void
    {
        [$provider] = $this->provider();
        $client = $this->client();
        $booking = $this->booking($client, $this->service($provider));
        $clientToken = $client->createToken('client', ['client:auth'])->plainTextToken;

        $this->withToken($clientToken)
            ->getJson('/api/client/v1/provider/bookings')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($clientToken)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/confirm")
            ->assertForbidden();

        // Without a bearer token at all the routes are simply unauthenticated.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/client/v1/provider/bookings')->assertUnauthorized();
    }

    public function test_the_customer_is_notified_when_their_provider_accepts_the_booking(): void
    {
        Notification::fake();

        [$provider, $providerUser, $token] = $this->provider();
        $client = $this->client();
        $booking = $this->booking($client, $this->service($provider));

        $this->withToken($token)
            ->patchJson("/api/client/v1/provider/bookings/{$booking->id}/confirm")
            ->assertOk();

        Notification::assertSentTo($client, BookingStatusNotification::class);
        Notification::assertNotSentTo($providerUser, BookingStatusNotification::class);
    }

    public function test_the_provider_is_notified_when_the_customer_cancels(): void
    {
        Notification::fake();

        [$provider, $providerUser] = $this->provider();
        $client = $this->client();
        $booking = $this->booking($client, $this->service($provider));

        $this->withToken($client->createToken('client', ['client:auth'])->plainTextToken)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/cancel", ['reason' => 'Plans changed.'])
            ->assertOk();

        Notification::assertSentTo($providerUser, BookingStatusNotification::class);
        Notification::assertNotSentTo($client, BookingStatusNotification::class);
    }

    /**
     * @return array{0: ProviderProfile, 1: User, 2: string}
     */
    private function provider(): array
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(8).'@skillserve.test',
            'user_type' => 'provider',
            'status' => 'active',
        ]);
        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Provider '.Str::random(5),
            'verification_status' => 'verified',
        ]);

        return [$profile, $user, $user->createToken('provider-test', ['client:auth'])->plainTextToken];
    }

    private function client(): User
    {
        return User::factory()->create([
            'email' => 'client.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
            'phone' => '09990001111',
        ]);
    }

    private function service(ProviderProfile $provider): Service
    {
        $category = ServiceCategory::create(['name' => 'Cleaning '.Str::random(5), 'status' => 'enabled']);

        return Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Deep Cleaning',
            'price' => 1500,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
            'is_hidden' => false,
        ]);
    }

    private function booking(User $client, Service $service, array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $service->provider_id,
            'booking_number' => 'BK-TEST-'.Str::random(12),
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'total_price' => $service->price,
            'service_price' => $service->price,
            'platform_fee' => 150,
            'currency' => $service->currency,
            'scheduled_date' => now()->addDay(),
            'scheduled_end_date' => now()->addDay()->addHour(),
        ], $attributes));
    }
}
