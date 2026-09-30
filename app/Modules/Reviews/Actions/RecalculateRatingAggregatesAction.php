<?php

namespace App\Modules\Reviews\Actions;

use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the stored ratings in step with the active reviews.
 *
 * A service's rating is the average of its active reviews. A provider's
 * rating is the average of its rated services' ratings, each service
 * counting once however many reviews it has: services rated 3.5, 5.0, 4.6,
 * 3.3 and 3.5 make a 3.98 provider. Services without an active review are
 * left out rather than counted as zero. Deleted and hidden services still
 * count, so removing a poorly rated service cannot lift the provider.
 *
 * Run it after anything that changes whether a review is active or its
 * rating.
 */
final class RecalculateRatingAggregatesAction extends BaseAction
{
    public function handle(int $serviceId, int $providerId): void
    {
        $service = Service::query()->withTrashed()->lockForUpdate()->findOrFail($serviceId);
        $provider = ProviderProfile::query()->lockForUpdate()->findOrFail($providerId);

        $serviceStats = Review::query()
            ->where('service_id', $serviceId)
            ->where('status', 'active')
            ->selectRaw('COALESCE(AVG(rating), 0) as average_rating, COUNT(*) as total_reviews')
            ->first();

        // Each service's rating is rounded as it is stored and shown, so the
        // provider's figure matches an average of the displayed ratings.
        $serviceRatings = Review::query()
            ->where('provider_id', $providerId)
            ->where('status', 'active')
            ->selectRaw('ROUND(AVG(rating), 2) as service_rating, COUNT(*) as review_count')
            ->groupBy('service_id');
        $providerStats = DB::query()
            ->fromSub($serviceRatings, 'service_ratings')
            ->selectRaw('COALESCE(AVG(service_rating), 0) as average_rating, COALESCE(SUM(review_count), 0) as total_reviews')
            ->first();

        $service->update([
            'average_rating' => round((float) $serviceStats->average_rating, 2),
            'total_reviews' => (int) $serviceStats->total_reviews,
        ]);
        $provider->update([
            'average_rating' => round((float) $providerStats->average_rating, 2),
            'total_reviews' => (int) $providerStats->total_reviews,
        ]);
    }
}
