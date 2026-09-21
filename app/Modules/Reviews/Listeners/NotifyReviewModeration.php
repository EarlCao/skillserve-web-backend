<?php

namespace App\Modules\Reviews\Listeners;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Events\ReviewHidden;
use App\Modules\Reviews\Events\ReviewRemoved;
use App\Modules\Reviews\Events\ReviewRestored;
use App\Modules\Reviews\Notifications\ReviewModerationNotification;

/**
 * The reviewer hears when their review is hidden, removed or restored; the
 * provider hears when a hidden review about them is shown again.
 */
class NotifyReviewModeration
{
    public function handle(ReviewHidden|ReviewRemoved|ReviewRestored $event): void
    {
        $review = $event->review;
        $review->loadMissing('service:id,title');
        $service = $review->service?->title;
        $about = $service ? "your review of \u{201C}{$service}\u{201D}" : 'your review';

        // Full accounts, not the narrow eager-loaded relations: realtime
        // delivery needs columns those selects leave out.
        $reviewer = User::query()->find($review->reviewer_id);

        $notice = match (true) {
            $event instanceof ReviewHidden => new ReviewModerationNotification(
                $review, 'hidden', 'Review hidden',
                ucfirst($about).' was hidden while our team reviews it against the community guidelines.',
            ),
            $event instanceof ReviewRemoved => new ReviewModerationNotification(
                $review, 'removed', 'Review removed',
                ucfirst($about).' was removed because it breaks the community guidelines.',
            ),
            default => new ReviewModerationNotification(
                $review, 'restored', 'Review visible again',
                ucfirst($about).' was reviewed and is visible again.',
            ),
        };

        if ($reviewer && ! $reviewer->is($event->actor)) {
            $reviewer->notify($notice);
        }

        if ($event instanceof ReviewRestored) {
            $providerUser = User::query()->find(
                ProviderProfile::query()->whereKey($review->provider_id)->value('user_id'),
            );

            $providerUser?->notify(new ReviewModerationNotification(
                $review, 'restored', 'Review visible again',
                'A review on your profile'.($service ? " for \u{201C}{$service}\u{201D}" : '').' was reviewed and is visible again.',
            ));
        }
    }
}
