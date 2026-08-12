<?php

namespace App\Modules\ServiceCategories\Services;

use App\Models\User;
use App\Modules\ServiceCategories\Actions\CreateServiceCategoryAction;
use App\Modules\ServiceCategories\Actions\CreateServiceSubcategoryAction;
use App\Modules\ServiceCategories\Actions\DeleteServiceCategoryAction;
use App\Modules\ServiceCategories\Actions\DeleteServiceSubcategoryAction;
use App\Modules\ServiceCategories\Actions\SetServiceCategoryStatusAction;
use App\Modules\ServiceCategories\Actions\UpdateServiceCategoryAction;
use App\Modules\ServiceCategories\Actions\UpdateServiceSubcategoryAction;
use App\Modules\ServiceCategories\Events\ServiceCategoryCreated;
use App\Modules\ServiceCategories\Events\ServiceCategoryDeleted;
use App\Modules\ServiceCategories\Events\ServiceCategoryStatusChanged;
use App\Modules\ServiceCategories\Events\ServiceCategoryUpdated;
use App\Modules\ServiceCategories\Events\ServiceSubcategoryCreated;
use App\Modules\ServiceCategories\Events\ServiceSubcategoryDeleted;
use App\Modules\ServiceCategories\Events\ServiceSubcategoryUpdated;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Orchestrates service category management: listing (search/filter/sort/
 * paginate), creation, updates, enable/disable, safe deletion, and the
 * nested subcategory CRUD. Controllers stay thin.
 */
class ServiceCategoryService extends BaseService
{
    /**
     * Columns that can be sorted on.
     */
    private const SORTABLE = ['name', 'created_at'];

    public function __construct(
        private readonly CreateServiceCategoryAction $createServiceCategoryAction,
        private readonly UpdateServiceCategoryAction $updateServiceCategoryAction,
        private readonly DeleteServiceCategoryAction $deleteServiceCategoryAction,
        private readonly SetServiceCategoryStatusAction $setServiceCategoryStatusAction,
        private readonly CreateServiceSubcategoryAction $createServiceSubcategoryAction,
        private readonly UpdateServiceSubcategoryAction $updateServiceSubcategoryAction,
        private readonly DeleteServiceSubcategoryAction $deleteServiceSubcategoryAction,
    ) {}

    /**
     * Paginated, searchable, filterable, sortable category listing with the
     * subcategory count on every row.
     *
     * @param  array{search?: string, status?: string, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = ServiceCategory::query()
            // Trashed subcategories are excluded from the count automatically
            // (SoftDeletes scope).
            ->withCount('subcategories')
            ->with('createdBy:id,name');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$term]);
            });
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters));
    }

    /**
     * Load a single category with its (non-trashed) subcategories and creator.
     */
    public function show(ServiceCategory $category): ServiceCategory
    {
        return $category->load([
            'subcategories' => fn ($query) => $query->orderBy('name'),
            'createdBy:id,name',
        ]);
    }

    /**
     * Create a category and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function store(array $validated, User $actor): ServiceCategory
    {
        return $this->transaction(function () use ($validated, $actor): ServiceCategory {
            $category = $this->createServiceCategoryAction->handle($validated, $actor);

            $category->load('createdBy:id,name');

            // Keeps the resource's subcategories_count field consistent with
            // the Swagger examples on every single-resource response.
            $category->loadCount('subcategories');

            event(new ServiceCategoryCreated(category: $category, actor: $actor, data: $validated));

            return $category;
        });
    }

    /**
     * Update a category's details and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(ServiceCategory $category, array $validated, User $actor): ServiceCategory
    {
        return $this->transaction(function () use ($category, $validated, $actor): ServiceCategory {
            $before = $this->snapshot($category);

            $this->updateServiceCategoryAction->handle($category, $validated);

            $category->load('createdBy:id,name');
            $category->loadCount('subcategories');

            event(new ServiceCategoryUpdated(
                category: $category,
                actor: $actor,
                before: $before,
                after: $this->snapshot($category),
            ));

            return $category;
        });
    }

    /**
     * Enable/disable a category and record the activity.
     */
    public function updateStatus(ServiceCategory $category, string $status, User $actor): ServiceCategory
    {
        return $this->transaction(function () use ($category, $status, $actor): ServiceCategory {
            $from = $category->status;

            $this->setServiceCategoryStatusAction->handle($category, $status);

            $category->load('createdBy:id,name');
            $category->loadCount('subcategories');

            if ($from !== $category->status) {
                event(new ServiceCategoryStatusChanged(
                    category: $category,
                    actor: $actor,
                    from: $from,
                    to: $category->status,
                ));
            }

            return $category;
        });
    }

    /**
     * Soft-delete a category (guarded against categories that still have
     * subcategories) and record the activity.
     */
    public function destroy(ServiceCategory $category, User $actor): void
    {
        $this->transaction(function () use ($category, $actor): void {
            $this->deleteServiceCategoryAction->handle($category, $actor);

            event(new ServiceCategoryDeleted(category: $category, actor: $actor));
        });
    }

    /**
     * Create a subcategory under a category and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function storeSubcategory(ServiceCategory $category, array $validated, User $actor): ServiceSubcategory
    {
        return $this->transaction(function () use ($category, $validated, $actor): ServiceSubcategory {
            $subcategory = $this->createServiceSubcategoryAction->handle($category, $validated, $actor);

            event(new ServiceSubcategoryCreated(subcategory: $subcategory, actor: $actor, data: $validated));

            return $subcategory;
        });
    }

    /**
     * Update a subcategory (scoped to its parent category) and record the
     * activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function updateSubcategory(
        ServiceCategory $category,
        ServiceSubcategory $subcategory,
        array $validated,
        User $actor,
    ): ServiceSubcategory {
        return $this->transaction(function () use ($category, $subcategory, $validated, $actor): ServiceSubcategory {
            $before = $this->snapshotSubcategory($subcategory);

            $this->updateServiceSubcategoryAction->handle($category, $subcategory, $validated);

            event(new ServiceSubcategoryUpdated(
                subcategory: $subcategory,
                actor: $actor,
                before: $before,
                after: $this->snapshotSubcategory($subcategory),
            ));

            return $subcategory;
        });
    }

    /**
     * Soft-delete a subcategory (scoped to its parent category) and record
     * the activity.
     */
    public function destroySubcategory(
        ServiceCategory $category,
        ServiceSubcategory $subcategory,
        User $actor,
    ): void {
        $this->transaction(function () use ($category, $subcategory, $actor): void {
            $this->deleteServiceSubcategoryAction->handle($category, $subcategory);

            event(new ServiceSubcategoryDeleted(subcategory: $subcategory, actor: $actor));
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
     * Capture the identity-relevant state of a category for audit logs.
     *
     * @return array<string, mixed>
     */
    private function snapshot(ServiceCategory $category): array
    {
        return [
            'name' => $category->name,
            'description' => $category->description,
            'status' => $category->status,
        ];
    }

    /**
     * Capture the identity-relevant state of a subcategory for audit logs.
     *
     * @return array<string, mixed>
     */
    private function snapshotSubcategory(ServiceSubcategory $subcategory): array
    {
        return [
            'category_id' => $subcategory->category_id,
            'name' => $subcategory->name,
            'description' => $subcategory->description,
            'status' => $subcategory->status,
        ];
    }
}
