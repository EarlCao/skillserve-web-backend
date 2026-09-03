<?php

namespace App\Modules\Reviews\Actions;

use App\Modules\Reviews\Models\Review;
use App\Shared\Actions\BaseAction;

final class RestoreReviewAction extends BaseAction
{
    public function handle(Review $review): Review
    {
        $review->update([
            'status' => 'active',
            'hidden_by' => null,
            'hidden_at' => null,
        ]);

        return $review;
    }
}
