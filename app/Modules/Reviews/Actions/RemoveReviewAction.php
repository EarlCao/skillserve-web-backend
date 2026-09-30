<?php

namespace App\Modules\Reviews\Actions;

use App\Models\User;
use App\Modules\Reviews\Models\Review;
use App\Shared\Actions\BaseAction;

final class RemoveReviewAction extends BaseAction
{
    public function __construct(
        private readonly RecalculateRatingAggregatesAction $recalculateRatingAggregates,
    ) {}

    public function handle(Review $review, User $actor): void
    {
        $review->update([
            'status' => 'removed',
            'removed_by' => $actor->id,
            'removed_at' => now(),
        ]);
        $review->delete();
        $this->recalculateRatingAggregates->handle($review->service_id, $review->provider_id);
    }
}
