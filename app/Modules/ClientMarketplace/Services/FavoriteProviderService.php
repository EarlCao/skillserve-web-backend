<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Helpers\PageSize;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A customer's saved providers. Saving and removing are idempotent, and
 * the list only ever shows providers the customer could still find in the
 * marketplace: one who is later suspended, unverified or made private drops
 * out of the list without the saved row being deleted.
 */
class FavoriteProviderService extends BaseService
{
    public function __construct(
        private readonly ClientCatalogService $catalogService,
    ) {}

    /** Most recently saved first. */
    public function index(User $client, array $filters): LengthAwarePaginator
    {
        return $this->catalogService->publicProvidersQuery()
            ->join('favorite_providers', 'favorite_providers.provider_profile_id', '=', 'provider_profiles.id')
            ->where('favorite_providers.user_id', $client->id)
            ->orderByDesc('favorite_providers.created_at')
            ->orderByDesc('favorite_providers.id')
            ->paginate(PageSize::from($filters));
    }

    /** Only a provider the customer can see can be saved (404 otherwise). */
    public function add(User $client, ProviderProfile $provider): ProviderProfile
    {
        $provider = $this->catalogService->publicProvidersQuery()->whereKey($provider->id)->firstOrFail();

        // insertOrIgnore leans on the unique pair, so two quick taps cannot
        // race into a duplicate-key error.
        $client->favoriteProviders()->newPivotQuery()->insertOrIgnore([
            'user_id' => $client->id,
            'provider_profile_id' => $provider->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $provider;
    }

    /** Removing a provider that was not saved is not an error. */
    public function remove(User $client, ProviderProfile $provider): void
    {
        $client->favoriteProviders()->detach($provider->id);
    }
}
