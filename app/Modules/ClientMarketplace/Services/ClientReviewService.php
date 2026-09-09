<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientMarketplace\Actions\CreateClientReviewAction;
use App\Modules\ClientMarketplace\Actions\RecalculateClientReviewAggregatesAction;
use App\Modules\ClientMarketplace\Actions\UpdateClientReviewAction;
use App\Modules\Reviews\Models\Review;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

class ClientReviewService extends BaseService
{
    public function __construct(
        private readonly CreateClientReviewAction $createReviewAction,
        private readonly UpdateClientReviewAction $updateReviewAction,
        private readonly RecalculateClientReviewAggregatesAction $recalculateAggregatesAction,
    ) {}

    public function index(User $client, array $filters): LengthAwarePaginator
    {
        return Review::query()
            ->where('reviewer_id', $client->id)
            ->with($this->reviewRelations())
            ->latest()
            ->paginate($this->perPage($filters));
    }

    public function create(User $client, array $data): Review
    {
        return $this->transaction(function () use ($client, $data): Review {
            $booking = Booking::query()
                ->where('client_id', $client->id)
                ->whereKey($data['booking_id'])
                ->with(['review' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                throw new ApiException('The booking was not found for this customer.', 404);
            }

            $this->assertCompleted($booking);

            if ($booking->review) {
                throw new ApiException('This booking already has a review.', 409);
            }

            $review = $this->createReviewAction->handle($client, $booking, $data);
            $this->recalculateAggregatesAction->handle($booking->service_id, $booking->provider_id);

            return $review->load($this->reviewRelations());
        });
    }

    public function update(User $client, Review $review, array $data): Review
    {
        return $this->transaction(function () use ($client, $review, $data): Review {
            $review = Review::query()
                ->where('reviewer_id', $client->id)
                ->whereKey($review->id)
                ->with('booking')
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCompleted($review->booking);
            $this->updateReviewAction->handle($review, $data);
            $this->recalculateAggregatesAction->handle($review->service_id, $review->provider_id);

            return $review->load($this->reviewRelations());
        });
    }

    private function assertCompleted(Booking $booking): void
    {
        if (! $booking->isCompleted()) {
            throw new ApiException(
                'Reviews can only be created or updated for completed bookings.',
                422,
                ['booking_id' => ['The booking is not completed.']],
            );
        }
    }

    private function reviewRelations(): array
    {
        return [
            'booking:id,booking_number',
            'reviewer:id,name',
            'provider:id,business_name',
            'service:id,title',
        ];
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
