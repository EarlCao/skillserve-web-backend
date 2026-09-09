<?php

namespace App\Modules\ProviderRecognition\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\ProviderRecognition\Requests\AssignBadgeRequest;
use App\Modules\ProviderRecognition\Requests\BadgeIndexRequest;
use App\Modules\ProviderRecognition\Requests\ProviderIndexRequest;
use App\Modules\ProviderRecognition\Requests\StoreBadgeRequest;
use App\Modules\ProviderRecognition\Requests\UpdateBadgeRequest;
use App\Modules\ProviderRecognition\Resources\ProviderBadgeResource;
use App\Modules\ProviderRecognition\Resources\RecognitionProviderResource;
use App\Modules\ProviderRecognition\Services\ProviderRecognitionService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Provider Recognition', description: 'Manage provider badges, featured providers, and top-rated providers')]
class ProviderRecognitionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderRecognitionService $recognitionService) {}

    #[OA\Get(path: '/api/provider-recognition/badges', summary: 'List provider badges', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15))], responses: [new OA\Response(response: 200, description: 'Paginated badges', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function badges(BadgeIndexRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('view provider recognition'), 403);

        return $this->paginated($this->recognitionService->badges($request->validated()), ProviderBadgeResource::class, 'Badges retrieved.');
    }

    #[OA\Post(path: '/api/provider-recognition/badges', summary: 'Create a provider badge', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'slug'], properties: [new OA\Property(property: 'name', type: 'string', maxLength: 100), new OA\Property(property: 'slug', type: 'string', maxLength: 120), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'color', type: 'string', maxLength: 30), new OA\Property(property: 'is_active', type: 'boolean')])), responses: [new OA\Response(response: 201, description: 'Badge created', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeBadge(StoreBadgeRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('manage provider badges'), 403);

        return $this->success(new ProviderBadgeResource($this->recognitionService->createBadge($request->validated(), $request->user())), 'Badge created.', [], 201);
    }

    #[OA\Put(path: '/api/provider-recognition/badges/{badge}', summary: 'Update a provider badge', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'badge', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'name', type: 'string', maxLength: 100), new OA\Property(property: 'slug', type: 'string', maxLength: 120), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'color', type: 'string', maxLength: 30), new OA\Property(property: 'is_active', type: 'boolean')])), responses: [new OA\Response(response: 200, description: 'Badge updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized'), new OA\Response(response: 404, description: 'Badge not found'), new OA\Response(response: 422, description: 'Validation error')])]
    public function updateBadge(UpdateBadgeRequest $request, ProviderBadge $badge): JsonResponse
    {
        abort_unless($request->user()->can('manage provider badges'), 403);

        return $this->success(new ProviderBadgeResource($this->recognitionService->updateBadge($badge, $request->validated(), $request->user())), 'Badge updated.');
    }

    #[OA\Delete(path: '/api/provider-recognition/badges/{badge}', summary: 'Delete a provider badge', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'badge', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Badge deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized'), new OA\Response(response: 404, description: 'Badge not found')])]
    public function destroyBadge(Request $request, ProviderBadge $badge): JsonResponse
    {
        abort_unless($request->user()->can('manage provider badges'), 403);
        $this->recognitionService->deleteBadge($badge, $request->user());

        return $this->success(null, 'Badge deleted.');
    }

    #[OA\Get(path: '/api/provider-recognition/providers', summary: 'List recognition providers', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'badge_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'is_featured', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['created_at', 'average_rating', 'total_bookings', 'total_reviews'], default: 'created_at')), new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')), new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15))], responses: [new OA\Response(response: 200, description: 'Paginated recognition providers', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function providers(ProviderIndexRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('view provider recognition'), 403);

        return $this->paginated($this->recognitionService->providers($request->validated()), RecognitionProviderResource::class, 'Providers retrieved.');
    }

    #[OA\Get(path: '/api/provider-recognition/top-rated', summary: 'List top-rated providers', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'badge_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'is_featured', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'min_rating', in: 'query', required: false, schema: new OA\Schema(type: 'number', format: 'float', minimum: 0, maximum: 5)), new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15))], responses: [new OA\Response(response: 200, description: 'Paginated top-rated providers', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function topRated(ProviderIndexRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('view top rated providers'), 403);

        return $this->paginated($this->recognitionService->topRated($request->validated()), RecognitionProviderResource::class, 'Top-rated providers retrieved.');
    }

    #[OA\Post(path: '/api/provider-recognition/providers/{provider}/badges', summary: 'Assign a badge to a provider', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['badge_id'], properties: [new OA\Property(property: 'badge_id', type: 'integer', minimum: 1)])), responses: [new OA\Response(response: 200, description: 'Badge assigned', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized'), new OA\Response(response: 422, description: 'Validation error')])]
    public function assignBadge(AssignBadgeRequest $request, ProviderProfile $provider): JsonResponse
    {
        abort_unless($request->user()->can('assign provider badges'), 403);
        $badge = ProviderBadge::query()->findOrFail($request->integer('badge_id'));

        return $this->success(new RecognitionProviderResource($this->recognitionService->assignBadge($provider, $badge, $request->user())), 'Badge assigned.');
    }

    #[OA\Delete(path: '/api/provider-recognition/providers/{provider}/badges/{badge}', summary: 'Remove a badge from a provider', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'badge', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Badge removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized'), new OA\Response(response: 404, description: 'Provider or badge not found')])]
    public function removeBadge(Request $request, ProviderProfile $provider, ProviderBadge $badge): JsonResponse
    {
        abort_unless($request->user()->can('assign provider badges'), 403);

        return $this->success(new RecognitionProviderResource($this->recognitionService->removeBadge($provider, $badge, $request->user())), 'Badge removed.');
    }

    #[OA\Patch(path: '/api/provider-recognition/providers/{provider}/featured', summary: 'Feature or unfeature a provider', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['is_featured'], properties: [new OA\Property(property: 'is_featured', type: 'boolean')])), responses: [new OA\Response(response: 200, description: 'Featured status updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized'), new OA\Response(response: 422, description: 'Validation error')])]
    public function featured(Request $request, ProviderProfile $provider): JsonResponse
    {
        abort_unless($request->user()->can('manage featured providers'), 403);
        $isFeatured = $request->validate(['is_featured' => ['required', 'boolean']])['is_featured'];

        return $this->success(new RecognitionProviderResource($this->recognitionService->toggleFeatured($provider, $isFeatured, $request->user())), $isFeatured ? 'Provider featured.' : 'Provider unfeatured.');
    }
}
