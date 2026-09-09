<?php

namespace App\Modules\ClientMarketplace\Actions;

use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;

final class RecalculateClientReviewAggregatesAction extends BaseAction
{
    public function handle(int $serviceId, int $providerId): void
    {
        $service = Service::query()->lockForUpdate()->findOrFail($serviceId);
        $provider = ProviderProfile::query()->lockForUpdate()->findOrFail($providerId);

        $serviceStats = Review::query()
            ->where('service_id', $serviceId)
            ->where('status', 'active')
            ->selectRaw('COALESCE(AVG(rating), 0) as average_rating, COUNT(*) as total_reviews')
            ->first();
        $providerStats = Review::query()
            ->where('provider_id', $providerId)
            ->where('status', 'active')
            ->selectRaw('COALESCE(AVG(rating), 0) as average_rating, COUNT(*) as total_reviews')
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
