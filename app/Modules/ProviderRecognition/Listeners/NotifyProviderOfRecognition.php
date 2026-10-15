<?php

namespace App\Modules\ProviderRecognition\Listeners;

use App\Models\User;
use App\Modules\ProviderRecognition\Events\ProviderRecognitionChanged;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Notifications\ProviderAccountNotification;

/**
 * Tells a provider when an administrator gives or takes away a badge, or
 * features them or takes the featured spot away — with the administrator's
 * reason (required when taking something away) or note.
 */
class NotifyProviderOfRecognition
{
    public function handle(ProviderRecognitionChanged $event): void
    {
        if (! $event->subject instanceof ProviderProfile) {
            return;
        }

        $user = User::query()->find($event->subject->user_id);
        if (! $user) {
            return;
        }

        $badge = $event->properties['badge'] ?? 'a';
        $reason = $event->properties['reason'] ?? null;

        [$action, $title, $message] = match ($event->action) {
            'provider_badge_assigned' => ['badge_assigned', 'You earned a badge', "You received the {$badge} badge. Customers see it on your profile."],
            'provider_badge_removed' => ['badge_removed', 'Badge removed', "Your {$badge} badge was removed."],
            'provider_featured' => ['featured', "You're featured", 'Your profile is now featured to customers.'],
            'provider_unfeatured' => ['unfeatured', 'No longer featured', 'Your profile is no longer featured.'],
            default => [null, null, null],
        };

        if ($action === null) {
            return;
        }

        $taken = in_array($action, ['badge_removed', 'unfeatured'], true);
        $message .= $reason ? ($taken ? " Reason: {$reason}" : " Note from SkillServe: {$reason}") : '';

        $user->notify(new ProviderAccountNotification('provider_recognition', $action, $title, $message, $reason));
    }
}
