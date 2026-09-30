<?php

namespace App\Modules\Reviews\Actions;

use App\Modules\Reviews\Models\Review;
use App\Shared\Actions\BaseAction;

final class RestoreReviewAction extends BaseAction
{
    public function __construct(
        private readonly RecalculateRatingAggregatesAction $recalculateRatingAggregates,
    ) {}

    public function handle(Review $review): Review
    {
        $review->update([
            'status' => 'active',
            'hidden_by' => null,
            'hidden_at' => null,
        ]);
        $this->recalculateRatingAggregates->handle($review->service_id, $review->provider_id);

        return $review;
    }
}
