<?php

namespace App\Modules\Reviews\Actions;

use App\Models\User;
use App\Modules\Reviews\Models\Review;
use App\Shared\Actions\BaseAction;

final class RemoveReviewAction extends BaseAction
{
    public function handle(Review $review, User $actor): void
    {
        $review->update([
            'status' => 'removed',
            'removed_by' => $actor->id,
            'removed_at' => now(),
        ]);
    }
}
