<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientMarketplace\Requests\FavoriteProviderIndexRequest;
use App\Modules\ClientMarketplace\Resources\ClientProviderResource;
use App\Modules\ClientMarketplace\Services\FavoriteProviderService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The signed-in customer's saved providers. Every route acts on the caller's
 * own list only; EnsureClient admits active, verified customer accounts.
 */
#[OA\Tag(name: 'Client Favorites', description: "The signed-in customer's saved providers")]
class FavoriteProviderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FavoriteProviderService $favoriteService,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/favorites',
        summary: "List the customer's saved providers",
        description: 'Most recently saved first. Providers who are no longer publicly listed (suspended, unverified, private) are left out.',
        tags: ['Client Favorites'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Saved providers', content: new OA\JsonContent(ref: '#/components/schemas/ClientProviderListEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified customer account required'),
            new OA\Response(response: 422, description: 'Invalid pagination parameters'),
        ],
    )]
    public function index(FavoriteProviderIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->favoriteService->index($request->user(), $request->validated()),
            ClientProviderResource::class,
            'Favorite providers retrieved.',
        );
    }

    #[OA\Put(
        path: '/api/client/v1/favorites/{provider}',
        summary: 'Save a provider',
        description: 'Idempotent: saving a provider that is already saved succeeds without a duplicate.',
        tags: ['Client Favorites'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, description: 'Provider profile id', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Provider saved', content: new OA\JsonContent(ref: '#/components/schemas/ClientProviderEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified customer account required'),
            new OA\Response(response: 404, description: 'Provider not found or not publicly listed'),
        ],
    )]
    public function store(Request $request, ProviderProfile $provider): JsonResponse
    {
        return $this->success(
            new ClientProviderResource($this->favoriteService->add($request->user(), $provider)),
            'Provider saved to favorites.',
        );
    }

    #[OA\Delete(
        path: '/api/client/v1/favorites/{provider}',
        summary: 'Remove a saved provider',
        description: 'Idempotent: removing a provider that is not saved also succeeds.',
        tags: ['Client Favorites'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, description: 'Provider profile id', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Provider removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified customer account required'),
            new OA\Response(response: 404, description: 'Provider not found'),
        ],
    )]
    public function destroy(Request $request, ProviderProfile $provider): JsonResponse
    {
        $this->favoriteService->remove($request->user(), $provider);

        return $this->success(null, 'Provider removed from favorites.');
    }
}
