<?php

namespace App\Modules\ProviderRecognition\Services;

use App\Models\User;
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

    public function createBadge(array $data): ProviderBadge
    {
        return ProviderBadge::query()->create($data);
    }

    public function updateBadge(ProviderBadge $badge, array $data): ProviderBadge
    {
        $badge->update($data);

        return $badge->refresh()->loadCount('providers');
    }

    public function deleteBadge(ProviderBadge $badge): void
    {
        $badge->delete();
    }

    public function providers(array $filters): LengthAwarePaginator
    {
        $query = ProviderProfile::query()
            ->with(['user:id,name,email', 'badges'])
            ->whereNull('suspended_at')
            ->whereHas('user', fn ($user) => $user->where('user_type', 'provider')->where('status', 'active'));
        $this->applyProviderFilters($query, $filters);

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->paginate($this->perPage($filters));
    }

    public function topRated(array $filters): LengthAwarePaginator
    {
        $filters['sort'] = 'average_rating';
        $filters['direction'] = 'desc';

        $query = ProviderProfile::query()
            ->with(['user:id,name,email', 'badges'])
            ->where('verification_status', 'verified')
            ->where('total_reviews', '>', 0)
            ->whereNull('suspended_at')
            ->whereHas('user', fn ($user) => $user->where('user_type', 'provider')->where('status', 'active'));
        $this->applyProviderFilters($query, $filters);

        if (! empty($filters['min_rating'])) {
            $query->where('average_rating', '>=', (float) $filters['min_rating']);
        }

        return $query->orderByDesc('average_rating')->orderByDesc('total_reviews')->paginate($this->perPage($filters));
    }

    public function assignBadge(ProviderProfile $provider, ProviderBadge $badge, User $actor): ProviderProfile
    {
        $this->assertEligibleProvider($provider);

        return $this->transaction(function () use ($provider, $badge, $actor): ProviderProfile {
            $lockedBadge = ProviderBadge::query()->lockForUpdate()->find($badge->id);

            if (! $lockedBadge || ! $lockedBadge->is_active) {
                throw new ApiException('Only active badges can be assigned.', 422);
            }

            DB::table('provider_badge_assignments')->insertOrIgnore([
                'provider_profile_id' => $provider->id,
                'provider_badge_id' => $badge->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $provider->load(['user:id,name,email', 'badges']);
        });
    }

    public function removeBadge(ProviderProfile $provider, ProviderBadge $badge): ProviderProfile
    {
        return $this->transaction(function () use ($provider, $badge): ProviderProfile {
            $provider->badges()->detach($badge->id);

            return $provider->load(['user:id,name,email', 'badges']);
        });
    }

    public function toggleFeatured(ProviderProfile $provider, bool $featured): ProviderProfile
    {
        $this->assertEligibleProvider($provider, requireVerified: $featured);
        $provider->update(['is_featured' => $featured]);

        return $provider->load(['user:id,name,email', 'badges']);
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
