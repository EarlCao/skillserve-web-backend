<?php

namespace App\Modules\Reviews\Services;

use App\Models\User;
use App\Modules\Reviews\Actions\HideReviewAction;
use App\Modules\Reviews\Actions\RemoveReviewAction;
use App\Modules\Reviews\Actions\RestoreReviewAction;
use App\Modules\Reviews\Events\ReviewHidden;
use App\Modules\Reviews\Events\ReviewRemoved;
use App\Modules\Reviews\Events\ReviewRestored;
use App\Modules\Reviews\Models\Review;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

class ReviewService extends BaseService
{
    private const SORTABLE = ['rating', 'created_at'];

    public function __construct(
        private readonly HideReviewAction $hideReviewAction,
        private readonly RestoreReviewAction $restoreReviewAction,
        private readonly RemoveReviewAction $removeReviewAction,
    ) {}

    /**
     * @param  array{search?: string, rating?: int, status?: string, is_reported?: bool, provider_id?: int, service_id?: int, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = Review::query()
            ->with([
                'reviewer:id,name,email',
                'provider:id,user_id,business_name',
                'provider.user:id,name',
                'service:id,title',
            ]);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(comment) LIKE ?', [$term])
                    ->orWhereHas('reviewer', function ($q2) use ($term): void {
                        $q2->whereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
                    })
                    ->orWhereHas('provider', function ($q2) use ($term): void {
                        $q2->whereRaw('LOWER(business_name) LIKE ?', [$term]);
                    })
                    ->orWhereHas('service', function ($q2) use ($term): void {
                        $q2->whereRaw('LOWER(title) LIKE ?', [$term]);
                    });
            });
        }

        if (! empty($filters['rating']) && $filters['rating'] !== '') {
            $query->where('rating', (int) $filters['rating']);
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if (isset($filters['is_reported']) && $filters['is_reported'] !== '') {
            $query->where('is_reported', (bool) $filters['is_reported']);
        }

        if (! empty($filters['provider_id'])) {
            $query->where('provider_id', $filters['provider_id']);
        }

        if (! empty($filters['service_id'])) {
            $query->where('service_id', $filters['service_id']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters));
    }

    public function show(Review $review): Review
    {
        return $review->load([
            'booking:id,booking_number',
            'reviewer:id,name,email',
            'provider:id,user_id,business_name',
            'provider.user:id,name,email',
            'service:id,title',
            'hiddenBy:id,name',
            'removedBy:id,name',
        ]);
    }

    public function toggleHide(Review $review, bool $isHidden, User $actor): Review
    {
        return $this->transaction(function () use ($review, $isHidden, $actor): Review {
            if ($isHidden) {
                $this->hideReviewAction->handle($review, $actor);
                event(new ReviewHidden(review: $review, actor: $actor));
            } else {
                $this->restoreReviewAction->handle($review);
                event(new ReviewRestored(review: $review, actor: $actor));
            }

            $review->load([
                'reviewer:id,name,email',
                'provider:id,user_id,business_name',
                'provider.user:id,name',
                'service:id,title',
                'hiddenBy:id,name',
            ]);

            return $review;
        });
    }

    public function destroy(Review $review, User $actor): void
    {
        // Removal is a reversible soft-delete; Data Management can restore the
        // retained audit record without exposing it in normal review queries.
        $this->transaction(function () use ($review, $actor): void {
            $this->removeReviewAction->handle($review, $actor);

            event(new ReviewRemoved(review: $review, actor: $actor));
        });
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
