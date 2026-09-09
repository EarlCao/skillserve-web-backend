<?php

namespace App\Modules\Providers\Services;

use App\Models\User;
use App\Modules\Providers\Actions\ActivateProviderAction;
use App\Modules\Providers\Actions\ApproveVerificationAction;
use App\Modules\Providers\Actions\RejectVerificationAction;
use App\Modules\Providers\Actions\RemoveVerificationAction;
use App\Modules\Providers\Actions\RequestAdditionalInfoAction;
use App\Modules\Providers\Actions\SuspendProviderAction;
use App\Modules\Providers\Events\ProviderActivated;
use App\Modules\Providers\Events\ProviderAdditionalInfoRequested;
use App\Modules\Providers\Events\ProviderSuspended;
use App\Modules\Providers\Events\ProviderVerificationApproved;
use App\Modules\Providers\Events\ProviderVerificationRejected;
use App\Modules\Providers\Events\ProviderVerificationRemoved;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationRequest;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Orchestrates provider management: listing (search/filter/sort/paginate),
 * profile viewing, verification review, approval, rejection, additional info
 * requests, suspension, activation, and verification removal.
 *
 * Controllers stay thin.
 */
class ProviderService extends BaseService
{
    /**
     * Columns that can be sorted on.
     */
    private const SORTABLE = ['created_at', 'average_rating', 'total_bookings', 'business_name'];

    public function __construct(
        private readonly ApproveVerificationAction $approveVerificationAction,
        private readonly RejectVerificationAction $rejectVerificationAction,
        private readonly RequestAdditionalInfoAction $requestAdditionalInfoAction,
        private readonly SuspendProviderAction $suspendProviderAction,
        private readonly ActivateProviderAction $activateProviderAction,
        private readonly RemoveVerificationAction $removeVerificationAction,
    ) {}

    /**
     * Paginated, searchable, filterable, sortable provider listing.
     *
     * @param  array{search?: string, status?: string, verification?: string, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = $this->withAggregates(ProviderProfile::query())
            ->with(['user:id,name,email,phone,created_at', 'verifiedBy:id,name', 'suspendedBy:id,name']);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($q) use ($term, $search): void {
                $q->whereRaw('LOWER(business_name) LIKE ?', [$term])
                    ->orWhereHas('user', function ($userQuery) use ($term, $search): void {
                        $userQuery->whereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$term]);

                        if (ctype_digit($search)) {
                            $userQuery->orWhere('id', (int) $search);
                        }
                    });
            });
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            if ($status === 'suspended') {
                $query->whereNotNull('suspended_at');
            } elseif ($status === 'active') {
                $query->whereNull('suspended_at');
            }
        }

        if ($verification = trim((string) ($filters['verification'] ?? ''))) {
            $query->where('verification_status', $verification);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';
        $sort = match ($sort) {
            'average_rating' => 'average_rating_avg',
            'total_bookings' => 'total_bookings_count',
            default => $sort,
        };

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters));
    }

    /**
     * Load a single provider profile with its relations.
     */
    public function show(ProviderProfile $profile): ProviderProfile
    {
        return $this->withAggregates(ProviderProfile::query())
            ->whereKey($profile->id)
            ->with([
                'user:id,name,email,phone,address,birthday,created_at',
                'verifiedBy:id,name',
                'suspendedBy:id,name',
                'latestVerificationRequest' => function ($query) {
                    $query->with(['documents', 'reviewedBy:id,name']);
                },
                'verificationRequests' => function ($query) {
                    $query->with(['documents', 'reviewedBy:id,name'])
                        ->latest()
                        ->limit(10);
                },
            ])
            ->firstOrFail();
    }

    /**
     * Approve a provider's verification request.
     */
    public function approveVerification(VerificationRequest $request, User $actor, ?string $notes = null): VerificationRequest
    {
        return $this->transaction(function () use ($request, $actor, $notes) {
            $result = $this->approveVerificationAction->handle($request, $actor, $notes);

            event(new ProviderVerificationApproved(
                providerProfile: $result->providerProfile,
                actor: $actor,
                notes: $notes,
            ));

            return $result;
        });
    }

    /**
     * Reject a provider's verification request.
     */
    public function rejectVerification(VerificationRequest $request, User $actor, string $reason): VerificationRequest
    {
        return $this->transaction(function () use ($request, $actor, $reason) {
            $result = $this->rejectVerificationAction->handle($request, $actor, $reason);

            event(new ProviderVerificationRejected(
                providerProfile: $result->providerProfile,
                actor: $actor,
                reason: $reason,
            ));

            return $result;
        });
    }

    /**
     * Request additional information from a provider.
     */
    public function requestAdditionalInfo(VerificationRequest $request, User $actor, string $message): VerificationRequest
    {
        return $this->transaction(function () use ($request, $actor, $message) {
            $result = $this->requestAdditionalInfoAction->handle($request, $actor, $message);

            event(new ProviderAdditionalInfoRequested(
                providerProfile: $result->providerProfile,
                actor: $actor,
                message: $message,
            ));

            return $result;
        });
    }

    /**
     * Suspend a provider.
     */
    public function suspend(ProviderProfile $profile, User $actor, string $reason): ProviderProfile
    {
        return $this->transaction(function () use ($profile, $actor, $reason) {
            $result = $this->suspendProviderAction->handle($profile, $actor, $reason);

            event(new ProviderSuspended(
                providerProfile: $result,
                actor: $actor,
                reason: $reason,
            ));

            return $result;
        });
    }

    /**
     * Activate a suspended provider.
     */
    public function activate(ProviderProfile $profile, User $actor): ProviderProfile
    {
        return $this->transaction(function () use ($profile, $actor) {
            $result = $this->activateProviderAction->handle($profile, $actor);

            event(new ProviderActivated(
                providerProfile: $result,
                actor: $actor,
            ));

            return $result;
        });
    }

    /**
     * Remove a provider's verified status.
     */
    public function removeVerification(ProviderProfile $profile, User $actor): ProviderProfile
    {
        return $this->transaction(function () use ($profile, $actor) {
            $result = $this->removeVerificationAction->handle($profile, $actor);

            event(new ProviderVerificationRemoved(
                providerProfile: $result,
                actor: $actor,
            ));

            return $result;
        });
    }

    /**
     * Get verification history for a provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public function verificationHistory(ProviderProfile $profile): array
    {
        return VerificationRequest::query()
            ->where('provider_profile_id', $profile->id)
            ->with(['documents', 'reviewedBy:id,name'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (VerificationRequest $request): array => [
                'id' => $request->id,
                'status' => $request->status,
                'notes' => $request->notes,
                'admin_notes' => $request->admin_notes,
                'rejection_reason' => $request->rejection_reason,
                'additional_info_request' => $request->additional_info_request,
                'submitted_at' => $request->submitted_at?->toIso8601String(),
                'reviewed_at' => $request->reviewed_at?->toIso8601String(),
                'reviewed_by' => $request->reviewed_by ? [
                    'id' => $request->reviewed_by->id,
                    'name' => $request->reviewed_by->name,
                ] : null,
                'documents_count' => $request->documents->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Clamp the requested page size between 1 and 100.
     *
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }

    private function withAggregates(Builder $query): Builder
    {
        return $query
            ->withCount([
                'services',
                'bookings as total_bookings_count',
                'bookings as completed_bookings_count' => fn ($bookingQuery) => $bookingQuery->where('status', 'completed'),
                'reviews as total_reviews_count' => fn ($reviewQuery) => $reviewQuery->where('status', 'active'),
            ])
            ->withAvg([
                'reviews as average_rating_avg' => fn ($reviewQuery) => $reviewQuery->where('status', 'active'),
            ], 'rating');
    }
}
