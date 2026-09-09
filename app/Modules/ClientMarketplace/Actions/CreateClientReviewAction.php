<?php

namespace App\Modules\ClientMarketplace\Actions;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Reviews\Models\Review;
use App\Shared\Actions\BaseAction;

final class CreateClientReviewAction extends BaseAction
{
    public function handle(User $client, Booking $booking, array $data): Review
    {
        $review = Review::create([
            'booking_id' => $booking->id,
            'reviewer_id' => $client->id,
            'provider_id' => $booking->provider_id,
            'service_id' => $booking->service_id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'status' => 'active',
        ]);

        $booking->update(['is_reviewed' => true]);

        return $review;
    }
}
