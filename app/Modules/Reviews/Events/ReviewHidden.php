<?php

namespace App\Modules\Reviews\Events;

use App\Models\User;
use App\Modules\Reviews\Models\Review;

class ReviewHidden
{
    public function __construct(
        public readonly Review $review,
        public readonly User $actor,
    ) {}
}
