<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Services\Actions\CreateServiceAction;
use App\Modules\Services\Actions\DeleteServiceAction;
use App\Modules\Services\Actions\UpdateServiceAction;
use App\Modules\Services\Events\ServiceCreated;
use App\Modules\Services\Events\ServiceDeleted;
use App\Modules\Services\Events\ServiceUpdated;
use App\Modules\Services\Models\Service;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Providers manage their own services. Every new service and every change
 * goes back to "pending" until an administrator approves it.
 */
class ProviderServiceService extends BaseService
{
    /** Fields whose change requires administrator re-approval. */
    private const SNAPSHOT_FIELDS = [
        'title', 'description', 'category_id', 'subcategory_id', 'price', 'price_type',
        'duration', 'location', 'status', 'approval_status', 'is_featured', 'is_hidden',
    ];

    private const RELATIONS = ['category:id,name', 'subcategory:id,name', 'provider:id,business_name,average_rating,total_reviews'];

    public function __construct(
        private readonly CreateServiceAction $createServiceAction,
        private readonly UpdateServiceAction $updateServiceAction,
        private readonly DeleteServiceAction $deleteServiceAction,
    ) {}

    public function index(User $providerUser, array $filters): LengthAwarePaginator
    {
        return Service::query()
            ->where('provider_id', $this->profile($providerUser)->id)
            ->when($filters['approval_status'] ?? null, fn ($query, $status) => $query->where('approval_status', $status))
            ->with(self::RELATIONS)
            ->latest()
            ->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 15))));
    }

    public function show(User $providerUser, Service $service): Service
    {
        return $this->owned($providerUser, $service)->load(self::RELATIONS);
    }

    public function create(User $providerUser, array $data): Service
    {
        $profile = $this->verifiedProfile($providerUser);

        return $this->transaction(function () use ($providerUser, $profile, $data): Service {
            $service = $this->createServiceAction->handle(
                array_merge($data, ['provider_id' => $profile->id, 'currency' => 'PHP']),
                $providerUser,
            );

            event(new ServiceCreated(service: $service, actor: $providerUser, data: $data));

            return $service->load(self::RELATIONS);
        });
    }

    public function update(User $providerUser, Service $service, array $data): Service
    {
        $this->verifiedProfile($providerUser);
        $service = $this->owned($providerUser, $service);

        // Saving identical values must not pull a live service back into review.
        if (! (clone $service)->fill($data)->isDirty()) {
            return $service->load(self::RELATIONS);
        }

        return $this->transaction(function () use ($providerUser, $service, $data): Service {
            $before = $service->only(self::SNAPSHOT_FIELDS);

            $this->updateServiceAction->handle($service, array_merge($data, [
                'approval_status' => 'pending',
                'status' => 'draft',
                'rejection_reason' => null,
                'approved_by' => null,
                'approved_at' => null,
                'updated_by' => $providerUser->id,
            ]));

            event(new ServiceUpdated(
                service: $service,
                actor: $providerUser,
                before: $before,
                after: $service->only(self::SNAPSHOT_FIELDS),
            ));

            return $service->load(self::RELATIONS);
        });
    }

    public function delete(User $providerUser, Service $service): void
    {
        $service = $this->owned($providerUser, $service);

        if ($service->bookings()->whereIn('status', ['pending', 'confirmed', 'active'])->exists()) {
            throw new ApiException('This service has open bookings. Complete or cancel them before deleting it.', 409);
        }

        $this->transaction(function () use ($providerUser, $service): void {
            $this->deleteServiceAction->handle($service, $providerUser);

            event(new ServiceDeleted(service: $service, actor: $providerUser));
        });
    }

    private function profile(User $providerUser): ProviderProfile
    {
        return $providerUser->providerProfile
            ?? throw new ApiException('Provider profile not found.', 404);
    }

    private function verifiedProfile(User $providerUser): ProviderProfile
    {
        $profile = $this->profile($providerUser);

        if (! $profile->isVerified()) {
            throw new ApiException('Your provider account must be verified before you can add or edit services.', 403);
        }

        return $profile;
    }

    /**
     * Another provider's service is reported as missing, not forbidden, so
     * service IDs cannot be probed.
     */
    private function owned(User $providerUser, Service $service): Service
    {
        if ((int) $service->provider_id !== (int) $this->profile($providerUser)->id) {
            throw new ApiException('Service not found.', 404);
        }

        return $service;
    }
}
