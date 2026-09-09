<?php

namespace App\Modules\ProviderRecognition\Services;

use App\Models\User;
use App\Modules\ProviderRecognition\Events\ProviderRecognitionChanged;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProviderRecognitionService extends BaseService
{
    private const SORTABLE = ['created_at', 'average_rating', 'total_bookings', 'total_reviews'];

    public function badges(array $filters): LengthAwarePaginator
    {
        $query = ProviderBadge::query()->withCount('providers');

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $term = '%'.mb_strtolower(trim($filters['search'])).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$term]));
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->latest()->paginate($this->perPage($filters));
    }

    public function createBadge(array $data, User $actor): ProviderBadge
    {
        return $this->transaction(function () use ($data, $actor): ProviderBadge {
            $badge = ProviderBadge::query()->create($data);
            event(new ProviderRecognitionChanged($badge, $actor, 'provider_badge_created', ['name' => $badge->name]));

            return $badge;
        });
    }

    public function updateBadge(ProviderBadge $badge, array $data, User $actor): ProviderBadge
    {
        return $this->transaction(function () use ($badge, $data, $actor): ProviderBadge {
            $badge->update($data);
            $badge = $badge->refresh()->loadCount('providers');
            event(new ProviderRecognitionChanged($badge, $actor, 'provider_badge_updated', ['changed' => array_keys($data)]));

            return $badge;
        });
    }

    public function deleteBadge(ProviderBadge $badge, User $actor): void
    {
        $this->transaction(function () use ($badge, $actor): void {
            $name = $badge->name;
            $badge->delete();
            event(new ProviderRecognitionChanged($badge, $actor, 'provider_badge_deleted', ['name' => $name]));
        });
    }

    public function providers(array $filters): LengthAwarePaginator
    {
        $query = $this->withAggregates(ProviderProfile::query())
            ->with(['user:id,name,email', 'badges'])
            ->whereNull('suspended_at')
            ->whereHas('user', fn ($user) => $user->where('user_type', 'provider')->where('status', 'active'));
        $this->applyProviderFilters($query, $filters);

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $sort = $this->aggregateSortColumn($sort);
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->paginate($this->perPage($filters));
    }

    public function topRated(array $filters): LengthAwarePaginator
    {
        $filters['sort'] = 'average_rating';
        $filters['direction'] = 'desc';

        $query = $this->withAggregates(ProviderProfile::query())
            ->with(['user:id,name,email', 'badges'])
            ->where('verification_status', 'verified')
            ->whereHas('reviews', fn ($reviewQuery) => $reviewQuery->where('status', 'active'))
            ->whereNull('suspended_at')
            ->whereHas('user', fn ($user) => $user->where('user_type', 'provider')->where('status', 'active'));
        $this->applyProviderFilters($query, $filters);

        if (! empty($filters['min_rating'])) {
            $query->whereRaw(
                '(SELECT COALESCE(AVG(reviews.rating), 0) FROM reviews WHERE reviews.provider_id = provider_profiles.id AND reviews.status = ?) >= ?',
                ['active', (float) $filters['min_rating']],
            );
        }

        return $query->orderByDesc('average_rating_avg')->orderByDesc('total_reviews_count')->paginate($this->perPage($filters));
    }

    public function assignBadge(ProviderProfile $provider, ProviderBadge $badge, User $actor): ProviderProfile
    {
        $this->assertEligibleProvider($provider);

        return $this->transaction(function () use ($provider, $badge, $actor): ProviderProfile {
            $lockedBadge = ProviderBadge::query()->lockForUpdate()->find($badge->id);

            if (! $lockedBadge || ! $lockedBadge->is_active) {
                throw new ApiException('Only active badges can be assigned.', 422);
            }

            $inserted = DB::table('provider_badge_assignments')->insertOrIgnore([
                'provider_profile_id' => $provider->id,
                'provider_badge_id' => $badge->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted) {
                event(new ProviderRecognitionChanged($provider, $actor, 'provider_badge_assigned', ['badge_id' => $badge->id]));
            }

            return $provider->load(['user:id,name,email', 'badges']);
        });
    }

    public function removeBadge(ProviderProfile $provider, ProviderBadge $badge, User $actor): ProviderProfile
    {
        return $this->transaction(function () use ($provider, $badge, $actor): ProviderProfile {
            $provider->badges()->detach($badge->id);
            event(new ProviderRecognitionChanged($provider, $actor, 'provider_badge_removed', ['badge_id' => $badge->id]));

            return $provider->load(['user:id,name,email', 'badges']);
        });
    }

    public function toggleFeatured(ProviderProfile $provider, bool $featured, User $actor): ProviderProfile
    {
        $this->assertEligibleProvider($provider, requireVerified: $featured);

        return $this->transaction(function () use ($provider, $featured, $actor): ProviderProfile {
            $provider->update(['is_featured' => $featured]);
            event(new ProviderRecognitionChanged(
                $provider,
                $actor,
                $featured ? 'provider_featured' : 'provider_unfeatured',
                ['is_featured' => $featured],
            ));

            return $provider->load(['user:id,name,email', 'badges']);
        });
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }

    private function applyProviderFilters(Builder $query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(business_name) LIKE ?', [$term])
                    ->orWhereHas('user', fn ($user) => $user
                        ->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            });
        }

        if (! empty($filters['badge_id'])) {
            $query->whereHas('badges', fn ($badge) => $badge->whereKey($filters['badge_id']));
        }

        if (isset($filters['is_featured']) && $filters['is_featured'] !== '') {
            $query->where('is_featured', (bool) $filters['is_featured']);
        }
    }

    private function withAggregates(Builder $query): Builder
    {
        return $query
            ->withCount([
                'bookings as total_bookings_count',
                'bookings as completed_bookings_count' => fn ($bookingQuery) => $bookingQuery->where('status', 'completed'),
                'reviews as total_reviews_count' => fn ($reviewQuery) => $reviewQuery->where('status', 'active'),
            ])
            ->withAvg([
                'reviews as average_rating_avg' => fn ($reviewQuery) => $reviewQuery->where('status', 'active'),
            ], 'rating');
    }

    private function aggregateSortColumn(string $sort): string
    {
        return match ($sort) {
            'average_rating' => 'average_rating_avg',
            'total_bookings' => 'total_bookings_count',
            'total_reviews' => 'total_reviews_count',
            default => $sort,
        };
    }

    private function assertEligibleProvider(ProviderProfile $provider, bool $requireVerified = false): void
    {
        $provider->loadMissing('user:id,user_type,status');

        if ($provider->user?->user_type !== 'provider' || $provider->user?->status !== 'active') {
            throw new ApiException('Only active provider accounts can receive recognition.', 422);
        }

        if ($requireVerified && $provider->verification_status !== 'verified') {
            throw new ApiException('Only verified providers can be featured.', 422);
        }

        if ($provider->isSuspended()) {
            throw new ApiException('Suspended providers cannot receive recognition.', 422);
        }
    }
}
