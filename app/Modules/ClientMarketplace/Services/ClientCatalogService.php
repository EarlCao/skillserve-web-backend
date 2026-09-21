<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Helpers\PageSize;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class ClientCatalogService extends BaseService
{
    public function categories(array $filters): LengthAwarePaginator
    {
        return ServiceCategory::query()
            ->where('status', 'enabled')
            ->select('service_categories.*')
            ->addSelect(['provider_count' => $this->publicServicesQuery()
                ->whereColumn('services.category_id', 'service_categories.id')
                ->selectRaw('COUNT(DISTINCT services.provider_id)')])
            ->with(['subcategories' => fn ($query) => $query
                ->where('status', 'enabled')
                ->orderBy('name')])
            ->orderBy('name')
            ->paginate($this->perPage($filters));
    }

    public function category(ServiceCategory $category): ServiceCategory
    {
        return ServiceCategory::query()
            ->whereKey($category->id)
            ->where('status', 'enabled')
            ->with(['subcategories' => fn ($query) => $query
                ->where('status', 'enabled')
                ->orderBy('name')])
            ->firstOrFail();
    }

    public function services(array $filters): LengthAwarePaginator
    {
        $query = $this->publicServicesQuery()
            ->with([
                'category:id,name',
                'subcategory:id,name',
                'provider:id,business_name,average_rating,total_reviews',
            ]);

        $this->applyServiceFilters($query, $filters);
        $sort = in_array($filters['sort'] ?? null, ['created_at', 'title', 'price', 'average_rating'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->paginate($this->perPage($filters));
    }

    public function service(Service $service): Service
    {
        return $this->publicServicesQuery()
            ->whereKey($service->id)
            ->with([
                'category:id,name',
                'subcategory:id,name',
                'provider:id,business_name,average_rating,total_reviews',
                'reviews' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('reviewer:id,name')
                    ->latest()
                    ->limit(20),
            ])
            ->firstOrFail();
    }

    public function providers(array $filters): LengthAwarePaginator
    {
        $query = $this->publicProvidersQuery();

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->whereRaw('LOWER(business_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(specialization) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(location) LIKE ?', [$term]);
            });
        }

        if (array_key_exists('featured', $filters) && $filters['featured'] !== null) {
            $query->where('is_featured', (bool) $filters['featured']);
        }

        $this->applyRatingFilter($query, $filters);

        // "Available" means the provider is taking new bookings and has at
        // least one service a client can book online — the same two rules
        // the booking flow enforces when the booking is actually created.
        if (array_key_exists('available', $filters) && $filters['available'] !== null) {
            $bookable = fn ($serviceQuery) => $this->applyBookableServiceFilters($serviceQuery);

            $filters['available']
                ? $query->where('is_accepting_bookings', true)->whereHas('services', $bookable)
                : $query->where(fn ($builder) => $builder
                    ->where('is_accepting_bookings', false)
                    ->orWhereDoesntHave('services', $bookable));
        }

        // Providers who publish hours on a given weekday. Providers with no
        // published schedule are excluded here: they can be booked at any
        // time, but they have not claimed to work that day.
        if (($filters['available_day'] ?? null) !== null) {
            $query->whereHas('availabilities', fn ($availabilityQuery) => $availabilityQuery
                ->where('day_of_week', (int) $filters['available_day']));
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas('services', fn ($serviceQuery) => $this->applyPublicServiceFilters(
                $serviceQuery->where('category_id', $filters['category_id']),
            ));
        }

        if (! empty($filters['subcategory_id'])) {
            $query->whereHas('services', fn ($serviceQuery) => $this->applyPublicServiceFilters(
                $serviceQuery->where('subcategory_id', $filters['subcategory_id']),
            ));
        }

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'average_rating', 'business_name'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->paginate($this->perPage($filters));
    }

    public function provider(ProviderProfile $provider): ProviderProfile
    {
        return $this->publicProvidersQuery()
            ->whereKey($provider->id)
            ->with([
                // The public profile shows work samples and badges; both are
                // eager-loaded here so the detail page is a single round trip.
                'portfolioItems' => fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
                // The profile shows the provider's published weekly hours.
                'availabilities',
                'badges' => fn ($query) => $query->where('is_active', true)->orderBy('name'),
                'services' => fn ($query) => $this->applyPublicServiceFilters($query->getQuery())
                    ->with(['category:id,name', 'subcategory:id,name']),
                'reviews' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with(['reviewer:id,name', 'service:id,title'])
                    ->latest()
                    ->limit(20),
            ])
            ->firstOrFail();
    }

    public function bookableService(int $serviceId): Service
    {
        $service = $this->publicServicesQuery()
            ->whereKey($serviceId)
            ->with(['provider:id,business_name,average_rating,total_reviews'])
            ->first();

        if (! $service) {
            throw (new ModelNotFoundException)->setModel(Service::class, [$serviceId]);
        }

        if ($service->price === null || (float) $service->price <= 0 || $service->price_type === 'custom') {
            throw new ApiException(
                'This service does not have a bookable price.',
                422,
                ['service_id' => ['The selected service cannot be booked online.']],
            );
        }

        return $service;
    }

    public function publicServicesQuery(): Builder
    {
        return $this->applyPublicServiceFilters(Service::query());
    }

    /**
     * Verified, active providers with a catalog summary for listing cards:
     * the lowest price across their public services and the category they
     * offer most services in.
     */
    /**
     * Constrains a `users` query to accounts that may appear in public
     * discovery: active, not deleted, and not hidden by the account's own
     * "Private profile" setting.
     */
    private function applyDiscoverableUserFilters(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->whereNull('deleted_at')
            // No preferences row means the defaults, where the profile is
            // public — so absence must not hide the provider.
            ->whereDoesntHave('preferences', fn ($preferenceQuery) => $preferenceQuery
                ->where('private_profile', true));
    }

    public static function featuredServicesEnabled(): bool
    {
        return (bool) app(SettingsService::class)->value('marketplace', 'featured_services_enabled');
    }

    /** Providers any visitor may discover: verified, active and public. */
    public function publicProvidersQuery(): Builder
    {
        $primaryCategory = ServiceCategory::query()
            ->select('service_categories.name')
            ->join('services', 'services.category_id', '=', 'service_categories.id')
            ->whereColumn('services.provider_id', 'provider_profiles.id')
            ->where('service_categories.status', 'enabled')
            ->where('services.status', 'published')
            ->where('services.approval_status', 'approved')
            ->where('services.is_hidden', false)
            ->whereNull('services.deleted_at')
            ->groupBy('service_categories.id', 'service_categories.name')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('service_categories.name')
            ->limit(1);

        return ProviderProfile::query()
            ->select('provider_profiles.*')
            ->addSelect(['primary_category' => $primaryCategory])
            ->withMin(['services as starting_price' => fn ($query) => $this->applyPublicServiceFilters($query)], 'price')
            ->where('verification_status', 'verified')
            ->whereNull('suspended_at')
            ->whereHas('user', fn ($query) => $this->applyDiscoverableUserFilters($query));
    }

    public function applyPublicServiceFilters(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where('approval_status', 'approved')
            ->where('is_hidden', false)
            ->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('status', 'enabled'))
            ->where(function ($subcategoryQuery): void {
                $subcategoryQuery->whereNull('subcategory_id')
                    ->orWhereHas('subcategory', fn ($query) => $query->where('status', 'enabled'));
            })
            ->whereHas('provider', function ($providerQuery): void {
                $providerQuery
                    ->where('verification_status', 'verified')
                    ->whereNull('suspended_at')
                    ->whereHas('user', fn ($userQuery) => $this->applyDiscoverableUserFilters($userQuery));
            });
    }

    private function applyServiceFilters(Builder $query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereHas('provider', fn ($providerQuery) => $providerQuery
                        ->whereRaw('LOWER(business_name) LIKE ?', [$term]));
            });
        }

        foreach (['category_id', 'subcategory_id', 'provider_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        // Featured services (M 3.5). With System Settings → Marketplace →
        // "Enable featured services" off, nothing counts as featured.
        if (array_key_exists('featured', $filters) && $filters['featured'] !== null) {
            if (! self::featuredServicesEnabled()) {
                if ($filters['featured']) {
                    $query->whereRaw('1 = 0');
                }
            } else {
                $query->where('is_featured', (bool) $filters['featured']);
            }
        }

        $this->applyRatingFilter($query, $filters);
    }

    /**
     * Narrows a services or providers query to results rated at or above the
     * requested number of stars. Both tables carry an `average_rating`.
     */
    private function applyRatingFilter(Builder $query, array $filters): void
    {
        if (($filters['min_rating'] ?? null) !== null) {
            $query->where('average_rating', '>=', (float) $filters['min_rating']);
        }
    }

    /**
     * Public services that also carry a price a client can be charged —
     * mirrors the guard in bookableService().
     */
    private function applyBookableServiceFilters(Builder $query): Builder
    {
        return $this->applyPublicServiceFilters($query)
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->where('price_type', '!=', 'custom');
    }

    private function perPage(array $filters): int
    {
        return PageSize::from($filters);
    }
}
