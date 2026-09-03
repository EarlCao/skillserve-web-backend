<?php

namespace App\Modules\Reviews\Listeners;

use App\Models\User;
use App\Modules\Reviews\Events\ReviewHidden;
use App\Modules\Reviews\Events\ReviewRemoved;
use App\Modules\Reviews\Events\ReviewRestored;

class LogReviewActivity
{
    public function handle(ReviewHidden|ReviewRestored|ReviewRemoved $event): void
    {
        match (true) {
            $event instanceof ReviewHidden => $this->log(
                'reviews', $event->actor, $event->review,
                ['status' => 'hidden'], 'review_hidden',
            ),
            $event instanceof ReviewRestored => $this->log(
                'reviews', $event->actor, $event->review,
                ['status' => 'active'], 'review_restored',
            ),
            $event instanceof ReviewRemoved => $this->log(
                'reviews', $event->actor, $event->review,
                ['status' => 'removed'], 'review_removed',
            ),
        };
    }

    private function log(
        string $logName,
        User $actor,
        $subject,
        array $properties,
        string $description,
    ): void {
        activity($logName)
            ->causedBy($actor)
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }
}
