<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientMarketplace\Requests\CatalogIndexRequest;
use App\Modules\ClientMarketplace\Resources\ClientCategoryResource;
use App\Modules\ClientMarketplace\Resources\ClientProviderResource;
use App\Modules\ClientMarketplace\Resources\ClientServiceResource;
use App\Modules\ClientMarketplace\Services\ClientCatalogService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Client Marketplace', description: 'Public customer catalog discovery')]
class ClientCatalogController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ClientCatalogService $catalogService,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/categories',
        summary: 'List enabled service categories and subcategories',
        tags: ['Client Marketplace'],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))],
        responses: [
            new OA\Response(response: 200, description: 'Enabled categories', content: new OA\JsonContent(ref: '#/components/schemas/ClientCategoryListEnvelope')),
        ],
    )]
    public function categories(CatalogIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->catalogService->categories($request->validated()),
            ClientCategoryResource::class,
            'Categories retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/categories/{category}',
        summary: 'Get an enabled category',
        tags: ['Client Marketplace'],
        parameters: [new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Category details', content: new OA\JsonContent(ref: '#/components/schemas/ClientCategoryEnvelope')),
            new OA\Response(response: 404, description: 'Category not found or disabled'),
        ],
    )]
    public function category(ServiceCategory $category): JsonResponse
    {
        return $this->success(
            new ClientCategoryResource($this->catalogService->category($category)),
            'Category retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/services',
        summary: 'List bookable published services',
        tags: ['Client Marketplace'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'subcategory_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'provider_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'title', 'price', 'average_rating'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Published services', content: new OA\JsonContent(ref: '#/components/schemas/ClientServiceListEnvelope'))],
    )]
    public function services(CatalogIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->catalogService->services($request->validated()),
            ClientServiceResource::class,
            'Services retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/services/{service}',
        summary: 'Get a bookable service',
        tags: ['Client Marketplace'],
        parameters: [new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Service details', content: new OA\JsonContent(ref: '#/components/schemas/ClientServiceEnvelope')),
            new OA\Response(response: 404, description: 'Service not found or unavailable'),
        ],
    )]
    public function service(Service $service): JsonResponse
    {
        return $this->success(
            new ClientServiceResource($this->catalogService->service($service)),
            'Service retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/providers',
        summary: 'List verified active providers',
        tags: ['Client Marketplace'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'subcategory_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'average_rating', 'business_name'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Verified providers', content: new OA\JsonContent(ref: '#/components/schemas/ClientProviderListEnvelope'))],
    )]
    public function providers(CatalogIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->catalogService->providers($request->validated()),
            ClientProviderResource::class,
            'Providers retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/providers/{provider}',
        summary: 'Get a verified active provider',
        tags: ['Client Marketplace'],
        parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Provider details', content: new OA\JsonContent(ref: '#/components/schemas/ClientProviderEnvelope')),
            new OA\Response(response: 404, description: 'Provider not found or unavailable'),
        ],
    )]
    public function provider(ProviderProfile $provider): JsonResponse
    {
        return $this->success(
            new ClientProviderResource($this->catalogService->provider($provider)),
            'Provider retrieved.',
        );
    }
}
