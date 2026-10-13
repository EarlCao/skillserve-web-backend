<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Shared\Helpers\BusinessTime;
use App\Shared\Services\BaseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The signed-in provider's performance, computed from their own bookings and
 * reviews at request time — so the Statistics screen is always current.
 *
 * Months are calendar months in the business timezone (Asia/Manila), ending
 * with the current month, so "this month" is the month the provider is in.
 * Earnings follow the app's Earnings screen: a completed booking's total
 * price less SkillServe's fee.
 */
class ProviderStatisticsService extends BaseService
{
    public const DEFAULT_MONTHS = 6;

    public const MAX_MONTHS = 12;

    /** @var array<int, string> */
    private const STATUSES = ['pending', 'confirmed', 'active', 'completed', 'cancelled', 'disputed'];

    /**
     * @return array<string, mixed>
     */
    public function forProvider(ProviderProfile $profile, int $months = self::DEFAULT_MONTHS): array
    {
        $months = max(1, min(self::MAX_MONTHS, $months));
        $now = CarbonImmutable::now(BusinessTime::timezone());
        $firstMonth = $now->startOfMonth()->subMonths($months - 1);

        $statusCounts = Booking::query()
            ->where('provider_id', $profile->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total);

        $completed = $statusCounts->get('completed', 0);
        $cancelled = $statusCounts->get('cancelled', 0);
        $finished = $completed + $cancelled;

        // Completed in the window, and received in the window, for the chart.
        $completedInWindow = Booking::query()
            ->where('provider_id', $profile->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $firstMonth->utc())
            ->get(['completed_at', 'total_price', 'platform_fee']);
        $receivedInWindow = Booking::query()
            ->where('provider_id', $profile->id)
            ->where('created_at', '>=', $firstMonth->utc())
            ->pluck('created_at');

        $monthly = $this->monthly($firstMonth, $months, $completedInWindow, $receivedInWindow);
        $thisMonth = end($monthly);

        $earningsTotal = (float) Booking::query()
            ->where('provider_id', $profile->id)
            ->where('status', 'completed')
            ->selectRaw('COALESCE(SUM(CASE WHEN total_price > platform_fee THEN total_price - platform_fee ELSE 0 END), 0) as earned')
            ->value('earned');

        return [
            'summary' => [
                // The provider's rating is the average of their services'
                // ratings (RecalculateRatingAggregatesAction), stored on the profile.
                'average_rating' => $this->money($profile->average_rating),
                'total_reviews' => (int) $profile->total_reviews,
                'total_bookings' => array_sum($statusCounts->all()),
                'completed_bookings' => $completed,
                'cancelled_bookings' => $cancelled,
                'pending_requests' => $statusCounts->get('pending', 0),
                'active_bookings' => $statusCounts->get('confirmed', 0) + $statusCounts->get('active', 0),
                'completion_rate' => $finished === 0 ? null : round($completed / $finished * 100, 1),
                'earnings_total' => $this->money($earningsTotal),
                'earnings_this_month' => $thisMonth['earnings'],
                'completed_this_month' => $thisMonth['completed'],
            ],
            'status_breakdown' => collect(self::STATUSES)
                ->mapWithKeys(fn (string $status): array => [$status => $statusCounts->get($status, 0)])
                ->all(),
            'monthly' => $monthly,
            'rating_breakdown' => $this->ratingBreakdown($profile),
            'top_services' => $this->topServices($profile),
            'currency' => 'PHP',
            'timezone' => BusinessTime::timezone(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * One entry per calendar month, oldest first, ending with this month.
     *
     * @param  Collection<int, Booking>  $completed
     * @param  Collection<int, mixed>  $received
     * @return array<int, array<string, mixed>>
     */
    private function monthly(CarbonImmutable $firstMonth, int $months, Collection $completed, Collection $received): array
    {
        $key = fn ($instant): string => BusinessTime::local($instant)->format('Y-m');

        $completedByMonth = $completed->groupBy(fn (Booking $booking): string => $key($booking->completed_at));
        $receivedByMonth = $received->countBy(fn ($createdAt): string => $key($createdAt));

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $firstMonth->addMonths($i);
            $bookings = $completedByMonth->get($month->format('Y-m'), collect());

            $series[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M'),
                'year' => (int) $month->format('Y'),
                'completed' => $bookings->count(),
                'received' => (int) $receivedByMonth->get($month->format('Y-m'), 0),
                'earnings' => $this->money($bookings->sum(
                    fn (Booking $booking): float => max(0, (float) $booking->total_price - (float) $booking->platform_fee),
                )),
            ];
        }

        return $series;
    }

    /**
     * How many published reviews gave each star rating, 5 down to 1.
     *
     * @return array<string, int>
     */
    private function ratingBreakdown(ProviderProfile $profile): array
    {
        $counts = Review::query()
            ->where('provider_id', $profile->id)
            ->where('status', 'active')
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        return collect([5, 4, 3, 2, 1])
            ->mapWithKeys(fn (int $stars): array => [(string) $stars => (int) $counts->get($stars, 0)])
            ->all();
    }

    /**
     * The services that have brought the most completed bookings.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topServices(ProviderProfile $profile): array
    {
        return Booking::query()
            ->join('services', 'services.id', '=', 'bookings.service_id')
            ->where('bookings.provider_id', $profile->id)
            ->where('bookings.status', 'completed')
            ->groupBy('services.id', 'services.title', 'services.average_rating')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('services.title')
            ->limit(5)
            ->select(['services.id', 'services.title', 'services.average_rating'])
            ->selectRaw('COUNT(*) as completed')
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'title' => $row->title,
                'average_rating' => $this->money($row->average_rating),
                'completed' => (int) $row->completed,
            ])
            ->all();
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
