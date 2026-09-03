<?php

namespace App\Modules\Reviews\Events;

use App\Models\User;
use App\Modules\Reviews\Models\Review;

class ReviewRemoved
{
    public function __construct(
        public readonly Review $review,
        public readonly User $actor,
    ) {}
}
