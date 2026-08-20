<?php

namespace App\Modules\Services\Services;

use App\Models\User;
use App\Modules\Services\Actions\ApproveServiceAction;
use App\Modules\Services\Actions\CreateServiceAction;
use App\Modules\Services\Actions\DeleteServiceAction;
use App\Modules\Services\Actions\FeatureServiceAction;
use App\Modules\Services\Actions\HideServiceAction;
use App\Modules\Services\Actions\RejectServiceAction;
use App\Modules\Services\Actions\UpdateServiceAction;
use App\Modules\Services\Events\ServiceApproved;
use App\Modules\Services\Events\ServiceCreated;
use App\Modules\Services\Events\ServiceDeleted;
use App\Modules\Services\Events\ServiceFeatured;
use App\Modules\Services\Events\ServiceHidden;
use App\Modules\Services\Events\ServiceRejected;
use App\Modules\Services\Events\ServiceUpdated;
use App\Modules\Services\Models\Service;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Orchestrates service management: listing (search/filter/sort/paginate),
 * creation, updates, approval, rejection, hiding, featuring, and deletion.
 * Controllers stay thin.
 */
class ServiceService extends BaseService
{
    /**
     * Columns that can be sorted on.
     */
    private const SORTABLE = ['title', 'created_at', 'average_rating', 'price'];

    public function __construct(
        private readonly CreateServiceAction $createServiceAction,
        private readonly UpdateServiceAction $updateServiceAction,
        private readonly ApproveServiceAction $approveServiceAction,
        private readonly RejectServiceAction $rejectServiceAction,
        private readonly HideServiceAction $hideServiceAction,
        private readonly FeatureServiceAction $featureServiceAction,
        private readonly DeleteServiceAction $deleteServiceAction,
    ) {}

    /**
     * Paginated, searchable, filterable, sortable service listing.
     *
     * @param  array{search?: string, status?: string, approval_status?: string, category_id?: int, provider_id?: int, is_featured?: bool, is_hidden?: bool, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = Service::query()
            ->with(['category:id,name', 'subcategory:id,name', 'provider:id,business_name', 'provider.user:id,name']);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereHas('provider', function ($q2) use ($term): void {
                        $q2->whereRaw('LOWER(business_name) LIKE ?', [$term]);
                    })
                    ->orWhereHas('category', function ($q2) use ($term): void {
                        $q2->whereRaw('LOWER(name) LIKE ?', [$term]);
                    });
            });
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if ($approvalStatus = trim((string) ($filters['approval_status'] ?? ''))) {
            $query->where('approval_status', $approvalStatus);
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['provider_id'])) {
            $query->where('provider_id', $filters['provider_id']);
        }

        if (isset($filters['is_featured']) && $filters['is_featured'] !== '') {
            $query->where('is_featured', (bool) $filters['is_featured']);
        }

        if (isset($filters['is_hidden']) && $filters['is_hidden'] !== '') {
            $query->where('is_hidden', (bool) $filters['is_hidden']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters));
    }

    /**
     * Load a single service with all relationships.
     */
    public function show(Service $service): Service
    {
        return $service->load([
            'category:id,name',
            'subcategory:id,name',
            'provider:id,business_name',
            'provider.user:id,name,email',
            'createdBy:id,name',
            'updatedBy:id,name',
            'approvedBy:id,name',
        ]);
    }

    /**
     * Create a service and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function store(array $validated, User $actor): Service
    {
        return $this->transaction(function () use ($validated, $actor): Service {
            $service = $this->createServiceAction->handle($validated, $actor);

            $service->load(['category:id,name', 'subcategory:id,name', 'provider:id,business_name', 'provider.user:id,name']);

            event(new ServiceCreated(service: $service, actor: $actor, data: $validated));

            return $service;
        });
    }

    /**
     * Update a service's details and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(Service $service, array $validated, User $actor): Service
    {
        return $this->transaction(function () use ($service, $validated, $actor): Service {
            $before = $this->snapshot($service);

            $this->updateServiceAction->handle($service, $validated);

            $service->load(['category:id,name', 'subcategory:id,name', 'provider:id,business_name', 'provider.user:id,name']);

            event(new ServiceUpdated(
                service: $service,
                actor: $actor,
                before: $before,
                after: $this->snapshot($service),
            ));

            return $service;
        });
    }

    /**
     * Approve a service and record the activity.
     */
    public function approve(Service $service, User $actor, ?string $notes = null): Service
    {
        return $this->transaction(function () use ($service, $actor, $notes): Service {
            $this->approveServiceAction->handle($service, $actor, $notes);

            $service->load(['category:id,name', 'subcategory:id,name', 'provider:id,business_name', 'provider.user:id,name', 'approvedBy:id,name']);

            event(new ServiceApproved(service: $service, actor: $actor, notes: $notes));

            return $service;
        });
    }

    /**
     * Reject a service and record the activity.
     */
    public function reject(Service $service, User $actor, string $reason): Service
    {
        return $this->transaction(function () use ($service, $actor, $reason): Service {
            $this->rejectServiceAction->handle($service, $actor, $reason);

            $service->load(['category:id,name', 'subcategory:id,name', 'provider:id,business_name', 'provider.user:id,name']);

            event(new ServiceRejected(service: $service, actor: $actor, reason: $reason));

            return $service;
        });
    }

    /**
     * Hide/unhide a service and record the activity.
     */
    public function toggleHide(Service $service, bool $isHidden, User $actor): Service
    {
        return $this->transaction(function () use ($service, $isHidden, $actor): Service {
            $this->hideServiceAction->handle($service, $isHidden);

            $service->load(['category:id,name', 'subcategory:id,name', 'provider:id,business_name', 'provider.user:id,name']);

            event(new ServiceHidden(service: $service, actor: $actor, isHidden: $isHidden));

            return $service;
        });
    }

    /**
     * Feature/unfeature a service and record the activity.
     */
    public function toggleFeature(Service $service, bool $isFeatured, User $actor): Service
    {
        return $this->transaction(function () use ($service, $isFeatured, $actor): Service {
            $this->featureServiceAction->handle($service, $isFeatured);

            $service->load(['category:id,name', 'subcategory:id,name', 'provider:id,business_name', 'provider.user:id,name']);

            event(new ServiceFeatured(service: $service, actor: $actor, isFeatured: $isFeatured));

            return $service;
        });
    }

    /**
     * Soft-delete a service and record the activity.
     */
    public function destroy(Service $service, User $actor): void
    {
        $this->transaction(function () use ($service, $actor): void {
            $this->deleteServiceAction->handle($service, $actor);

            event(new ServiceDeleted(service: $service, actor: $actor));
        });
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

    /**
     * Capture the identity-relevant state of a service for audit logs.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Service $service): array
    {
        return [
            'title' => $service->title,
            'description' => $service->description,
            'category_id' => $service->category_id,
            'status' => $service->status,
            'approval_status' => $service->approval_status,
            'is_featured' => $service->is_featured,
            'is_hidden' => $service->is_hidden,
        ];
    }
}
