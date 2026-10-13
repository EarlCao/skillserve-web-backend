<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientAuthentication\Services\ClientSessionService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_chart_ends_with_the_current_month_in_business_time(): void
    {
        // 2026-10-31 18:00 UTC is already November 1 in Manila (UTC+8).
        $this->travelTo(CarbonImmutable::parse('2026-10-31 18:00:00', 'UTC'));
        [$profile, $token] = $this->provider();

        $months = $this->withToken($token)
            ->getJson('/api/client/v1/provider/statistics')
            ->assertOk()
            ->json('data.monthly');

        $this->assertCount(6, $months);
        $this->assertSame(['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'], array_column($months, 'label'));
        $this->assertSame('2026-11', end($months)['month']);

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/statistics?months=12')
            ->assertOk()
            ->assertJsonCount(12, 'data.monthly');
    }

    public function test_statistics_come_from_the_providers_own_bookings_and_reviews(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 04:00:00', 'UTC'));
        [$profile, $token] = $this->provider(['average_rating' => 4.5, 'total_reviews' => 3]);
        $service = $this->service($profile, 'Aircon cleaning');
        $other = $this->service($profile, 'Pipe repair');

        // This month: two completed (₱1000 − ₱100 fee, ₱500 − ₱50), one cancelled.
        $first = $this->booking($profile, $service, ['status' => 'completed', 'completed_at' => now()->subDay(), 'total_price' => 1000, 'platform_fee' => 100]);
        $second = $this->booking($profile, $other, ['status' => 'completed', 'completed_at' => now()->subDays(2), 'total_price' => 500, 'platform_fee' => 50]);
        $this->booking($profile, $service, ['status' => 'cancelled']);
        // Last month: one completed. Plus open work.
        $third = $this->booking($profile, $service, ['status' => 'completed', 'completed_at' => now()->subMonth(), 'total_price' => 200, 'platform_fee' => 20]);
        $this->booking($profile, $service, ['status' => 'pending']);
        $this->booking($profile, $service, ['status' => 'confirmed']);

        $this->review($first, 5);
        $this->review($second, 5);
        $this->review($third, 3);

        // Another provider's work never counts.
        [$stranger] = $this->provider();
        $this->booking($stranger, $this->service($stranger, 'Elsewhere'), ['status' => 'completed', 'completed_at' => now(), 'total_price' => 9999, 'platform_fee' => 0]);

        $data = $this->withToken($token)
            ->getJson('/api/client/v1/provider/statistics')
            ->assertOk()
            ->assertJsonPath('data.summary.average_rating', '4.50')
            ->assertJsonPath('data.summary.total_bookings', 6)
            ->assertJsonPath('data.summary.completed_bookings', 3)
            ->assertJsonPath('data.summary.cancelled_bookings', 1)
            ->assertJsonPath('data.summary.pending_requests', 1)
            ->assertJsonPath('data.summary.active_bookings', 1)
            ->assertJsonPath('data.summary.completion_rate', 75)
            ->assertJsonPath('data.summary.earnings_total', '1530.00')
            ->assertJsonPath('data.summary.earnings_this_month', '1350.00')
            ->assertJsonPath('data.summary.completed_this_month', 2)
            ->assertJsonPath('data.rating_breakdown', ['5' => 2, '4' => 0, '3' => 1, '2' => 0, '1' => 0])
            ->assertJsonPath('data.top_services.0.title', 'Aircon cleaning')
            ->assertJsonPath('data.top_services.0.completed', 2)
            ->json('data');

        $byMonth = collect($data['monthly'])->keyBy('month');
        $this->assertSame(2, $byMonth['2026-10']['completed']);
        $this->assertSame(1, $byMonth['2026-09']['completed']);
        $this->assertSame('180.00', $byMonth['2026-09']['earnings']);
    }

    public function test_only_a_provider_may_read_statistics_and_the_window_is_validated(): void
    {
        [, $token] = $this->provider();

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/statistics?months=13')
            ->assertUnprocessable();

        Auth::forgetGuards();
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active', 'email_verified_at' => now()]);
        $this->withToken(app(ClientSessionService::class)->issue($customer)['token'])
            ->getJson('/api/client/v1/provider/statistics')
            ->assertForbidden();

        Auth::forgetGuards();
        $this->flushHeaders()->getJson('/api/client/v1/provider/statistics')->assertUnauthorized();
    }

    /**
     * @return array{0: ProviderProfile, 1: string}
     */
    private function provider(array $attributes = []): array
    {
        $user = User::factory()->create([
            'user_type' => 'provider',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $profile = ProviderProfile::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Stats Provider '.Str::random(4),
            'verification_status' => 'verified',
        ], $attributes));

        return [$profile, app(ClientSessionService::class)->issue($user)['token']];
    }

    private function service(ProviderProfile $profile, string $title): Service
    {
        $category = ServiceCategory::create(['name' => 'Stats '.Str::random(6), 'status' => 'enabled']);

        return Service::create([
            'provider_id' => $profile->id,
            'category_id' => $category->id,
            'title' => $title,
            'price' => 100,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
    }

    private function booking(ProviderProfile $profile, Service $service, array $attributes = []): Booking
    {
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        return Booking::create(array_merge([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $profile->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(10)),
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'PHP',
            'scheduled_date' => now()->addDay(),
        ], $attributes));
    }

    private function review(Booking $booking, int $stars): void
    {
        Review::query()->create([
            'booking_id' => $booking->id,
            'reviewer_id' => $booking->client_id,
            'provider_id' => $booking->provider_id,
            'service_id' => $booking->service_id,
            'rating' => $stars,
            'comment' => 'Fine work.',
            'status' => 'active',
        ]);
    }
}
