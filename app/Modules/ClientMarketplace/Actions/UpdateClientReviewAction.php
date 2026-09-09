<?php

namespace App\Modules\ClientMarketplace\Actions;

use App\Modules\Reviews\Models\Review;
use App\Shared\Actions\BaseAction;

final class UpdateClientReviewAction extends BaseAction
{
    public function handle(Review $review, array $data): Review
    {
        $review->update($data);

        return $review->fresh();
    }
}
