<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
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
    /** Words beyond this are ignored, so one search cannot build a huge query. */
    private const MAX_SEARCH_WORDS = 8;

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
                'provider:id,user_id,business_name,average_rating,total_reviews',
                'provider.user:id,name,profile_photo_path',
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
                'provider:id,user_id,business_name,average_rating,total_reviews',
                'provider.user:id,name,profile_photo_path',
                'reviews' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('reviewer:id,name')
                    ->latest()
                    ->limit(20),
            ])
            ->firstOrFail();
    }

    /**
     * A public service's published reviews, newest first, with what the
     * customer needs to weigh them: the service's rating, how many reviews it
     * has, and how many gave each star. A service the public cannot see is
     * reported as missing.
     *
     * @return array{0: LengthAwarePaginator, 1: array<string, mixed>}
     */
    public function serviceReviews(Service $service, array $filters): array
    {
        $service = $this->publicServicesQuery()->whereKey($service->id)->firstOrFail();

        $published = fn () => Review::query()
            ->where('service_id', $service->id)
            ->where('status', 'active');

        $counts = $published()
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $reviews = $published()
            ->when($filters['rating'] ?? null, fn (Builder $query, $stars) => $query->where('rating', (int) $stars))
            ->with(['reviewer:id,name'])
            ->latest()
            ->orderByDesc('id')
            ->paginate(max(1, min(50, (int) ($filters['per_page'] ?? 10))));

        return [$reviews, [
            'rating' => [
                'average' => number_format((float) $service->average_rating, 2, '.', ''),
                'total' => (int) $counts->sum(),
                'breakdown' => collect([5, 4, 3, 2, 1])
                    ->mapWithKeys(fn (int $stars): array => [(string) $stars => (int) $counts->get($stars, 0)])
                    ->all(),
            ],
        ]];
    }

    public function providers(array $filters): LengthAwarePaginator
    {
        $query = $this->publicProvidersQuery();

        foreach ($this->searchTerms($filters) as $term) {
            $query->where(fn (Builder $builder) => $this->matchProvider($builder, $term));
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
                'portfolioItems' => fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id')->with('images'),
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
            ->with(['provider:id,user_id,business_name,average_rating,total_reviews', 'provider.user:id,name,profile_photo_path'])
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
            // The account name stands in for a missing business name, and the
            // account photo is the provider's avatar on cards and the profile.
            ->with('user:id,name,profile_photo_path')
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
        foreach ($this->searchTerms($filters) as $term) {
            $query->where(fn (Builder $builder) => $this->matchService($builder, $term));
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
     * The search as LIKE patterns, one per word. Every word has to match
     * somewhere — "laptop cebu" finds a laptop service in Cebu — and a word
     * matches anywhere inside a field, so "electronic" finds "Electronics".
     * LIKE wildcards the customer typed are matched literally, escaped with
     * "!" — a backslash would also need escaping for PDO's placeholder parser
     * on PostgreSQL.
     *
     * @return array<int, string>
     */
    private function searchTerms(array $filters): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $words = array_unique(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return array_map(
            fn (string $word): string => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%',
            array_slice($words, 0, self::MAX_SEARCH_WORDS),
        );
    }

    /**
     * A service matches a word through anything a customer sees on its card or
     * detail page: its own text, its category and subcategory, and who offers
     * it.
     */
    private function matchService(Builder $query, string $term): void
    {
        $this->whereContains($query, ['title', 'description', 'location'], $term);
        $query->orWhereHas('category', fn (Builder $categoryQuery) => $this->whereContains($categoryQuery, ['name'], $term))
            ->orWhereHas('subcategory', fn (Builder $subcategoryQuery) => $this->whereContains($subcategoryQuery, ['name'], $term))
            ->orWhereHas('provider', fn (Builder $providerQuery) => $providerQuery
                ->where(fn (Builder $builder) => $this->matchProviderIdentity($builder, $term)));
    }

    /**
     * A provider matches a word through their name and profile, or through
     * any service they publish — so searching "electronics" lists the people
     * who offer electronics work, not only the listings.
     */
    private function matchProvider(Builder $query, string $term): void
    {
        $this->matchProviderIdentity($query, $term);
        $query->orWhereHas('services', fn (Builder $serviceQuery) => $this->applyPublicServiceFilters($serviceQuery)
            ->where(function (Builder $builder) use ($term): void {
                $this->whereContains($builder, ['title', 'description'], $term);
                $builder->orWhereHas('category', fn (Builder $categoryQuery) => $this->whereContains($categoryQuery, ['name'], $term))
                    ->orWhereHas('subcategory', fn (Builder $subcategoryQuery) => $this->whereContains($subcategoryQuery, ['name'], $term));
            }));
    }

    /**
     * The provider's own name and profile text, including their account name.
     * Callers wrap it in a group: it ends in an OR.
     */
    private function matchProviderIdentity(Builder $query, string $term): void
    {
        $this->whereContains($query, ['business_name', 'specialization', 'bio', 'location', 'CAST(skills AS TEXT)'], $term);
        $query->orWhereHas('user', fn (Builder $userQuery) => $this->whereContains($userQuery, ['name'], $term));
    }

    /**
     * Case-insensitive "contains" over [columns], OR-ed inside a group of its
     * own — inside a whereHas the OR must not reach the relation's join
     * condition. Columns are fixed names from this class, never input.
     *
     * @param  array<int, string>  $columns
     */
    private function whereContains(Builder $query, array $columns, string $term): void
    {
        $query->where(function (Builder $group) use ($columns, $term): void {
            foreach ($columns as $column) {
                $group->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$term]);
            }
        });
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
