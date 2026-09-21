<?php

namespace App\Modules\Reviews\Notifications;

use App\Modules\Reviews\Models\Review;
use App\Shared\Notifications\BaseNotification;

/**
 * Tells someone that an administrator hid, removed or restored a review.
 *
 * No Settings category: a moderation notice about your own content is not
 * something a user can switch off, like account and security messages.
 */
class ReviewModerationNotification extends BaseNotification
{
    public function __construct(
        private readonly Review $review,
        private readonly string $action,
        private readonly string $title,
        private readonly string $message,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_moderation',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'review_id' => $this->review->id,
            // Lets the app open the booking the review belongs to.
            'booking_id' => $this->review->booking_id,
            'service_title' => $this->review->service?->title,
        ];
    }
}
