<?php

namespace App\Modules\Reviews\Actions;

use App\Models\User;
use App\Modules\Reviews\Models\Review;
use App\Shared\Actions\BaseAction;

final class HideReviewAction extends BaseAction
{
    public function handle(Review $review, User $actor): Review
    {
        $review->update([
            'status' => 'hidden',
            'hidden_by' => $actor->id,
            'hidden_at' => now(),
        ]);

        return $review;
    }
}
