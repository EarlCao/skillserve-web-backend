<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingRescheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rescheduling_a_confirmed_booking_sends_it_back_to_the_provider(): void
    {
        Notification::fake();

        [$provider, $providerUser] = $this->provider();
        $client = $this->client();
        $booking = $this->booking($client, $this->service($provider), [
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'scheduled_date' => Carbon::parse('+2 days 10:00'),
            'scheduled_end_date' => Carbon::parse('+2 days 12:30'),
        ]);
        $newStart = Carbon::parse('+3 days 14:00');

        $this->withToken($this->token($client))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", [
                'scheduled_date' => $newStart->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.confirmed_at', null)
            ->assertJsonPath('data.scheduled_date', $newStart->toIso8601String())
            // Without an explicit end the booking keeps its 2.5-hour length.
            ->assertJsonPath('data.scheduled_end_date', $newStart->copy()->addMinutes(150)->toIso8601String());

        $this->assertNotNull($booking->fresh()->rescheduled_at);

        Notification::assertSentTo(
            $providerUser,
            BookingStatusNotification::class,
            fn (BookingStatusNotification $notification) => $notification->toArray($providerUser)['action'] === 'rescheduled',
        );
        Notification::assertNotSentTo($client, BookingStatusNotification::class);

        $this->assertDatabaseHas('activity_log', ['subject_id' => $booking->id, 'description' => 'booking_rescheduled']);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $booking->id, 'description' => 'booking_status_changed']);
    }

    public function test_a_pending_booking_stays_pending_and_can_take_an_explicit_end(): void
    {
        [$provider] = $this->provider();
        $client = $this->client();
        $booking = $this->booking($client, $this->service($provider));
        $start = Carbon::parse('+4 days 09:00');
        $end = $start->copy()->addHours(3);

        $this->withToken($this->token($client))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", [
                'scheduled_date' => $start->toIso8601String(),
                'scheduled_end_date' => $end->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.scheduled_end_date', $end->toIso8601String());

        $this->assertDatabaseMissing('activity_log', ['subject_id' => $booking->id, 'description' => 'booking_status_changed']);
    }

    public function test_only_pending_or_confirmed_bookings_can_be_rescheduled(): void
    {
        [$provider] = $this->provider();
        $client = $this->client();
        $service = $this->service($provider);

        foreach (['active', 'completed', 'cancelled', 'disputed'] as $status) {
            $booking = $this->booking($client, $service, ['status' => $status]);

            $this->withToken($this->token($client))
                ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", [
                    'scheduled_date' => Carbon::parse('+5 days 10:00')->toIso8601String(),
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');

            $this->assertNull($booking->fresh()->rescheduled_at);
        }
    }

    public function test_the_new_time_must_be_future_and_different(): void
    {
        [$provider] = $this->provider();
        $client = $this->client();
        $booking = $this->booking($client, $this->service($provider));
        $token = $this->token($client);

        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", [
                'scheduled_date' => now()->subHour()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_date');

        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", [
                'scheduled_date' => $booking->scheduled_date->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_date');
    }

    public function test_the_new_time_must_not_overlap_another_booking_but_may_overlap_its_own(): void
    {
        [$provider] = $this->provider();
        $client = $this->client();
        $service = $this->service($provider);
        $token = $this->token($client);
        $booking = $this->booking($client, $service, [
            'scheduled_date' => Carbon::parse('+2 days 10:00'),
            'scheduled_end_date' => Carbon::parse('+2 days 12:00'),
        ]);
        $this->booking($this->client(), $service, [
            'status' => 'confirmed',
            'scheduled_date' => Carbon::parse('+3 days 10:00'),
            'scheduled_end_date' => Carbon::parse('+3 days 12:00'),
        ]);

        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", [
                'scheduled_date' => Carbon::parse('+3 days 11:00')->toIso8601String(),
            ])
            ->assertStatus(409);

        // Sliding an hour later overlaps only the booking's own old window.
        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", [
                'scheduled_date' => Carbon::parse('+2 days 11:00')->toIso8601String(),
            ])
            ->assertOk();
    }

    public function test_the_new_time_must_fall_inside_the_provider_hours_even_while_paused(): void
    {
        [$provider] = $this->provider();
        $client = $this->client();
        $booking = $this->booking($client, $this->service($provider));
        $token = $this->token($client);

        $day = Carbon::parse('next monday')->addWeek();
        $provider->availabilities()->create(['day_of_week' => $day->dayOfWeek, 'start_time' => '09:00', 'end_time' => '17:00']);
        // Pausing new bookings does not block moving an existing one.
        $provider->update(['is_accepting_bookings' => false]);

        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", [
                'scheduled_date' => $day->copy()->setTime(18, 0)->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_date');

        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", [
                'scheduled_date' => $day->copy()->setTime(10, 0)->toIso8601String(),
            ])
            ->assertOk();
    }

    public function test_only_the_booking_owner_can_reschedule(): void
    {
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($this->client(), $this->service($provider));
        $body = ['scheduled_date' => Carbon::parse('+5 days 10:00')->toIso8601String()];

        $this->withToken($this->token($this->client()))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", $body)
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($providerUser))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/reschedule", $body)
            ->assertForbidden();

        $this->assertNull($booking->fresh()->rescheduled_at);
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
        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Provider '.Str::random(5),
            'verification_status' => 'verified',
        ]);

        return [$profile, $user];
    }

    private function client(): User
    {
        return User::factory()->create([
            'email' => 'client.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('test', ['client:auth'])->plainTextToken;
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
            'scheduled_date' => Carbon::parse('+1 day 10:00'),
            'scheduled_end_date' => Carbon::parse('+1 day 11:00'),
        ], $attributes));
    }
}
