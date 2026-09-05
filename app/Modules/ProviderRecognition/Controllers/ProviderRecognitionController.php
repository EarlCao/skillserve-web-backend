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

    #[OA\Get(path: '/api/provider-recognition/badges', summary: 'List provider badges', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Badges', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function badges(BadgeIndexRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('view provider recognition'), 403);

        return $this->paginated($this->recognitionService->badges($request->validated()), ProviderBadgeResource::class, 'Badges retrieved.');
    }

    #[OA\Post(path: '/api/provider-recognition/badges', summary: 'Create a provider badge', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 201, description: 'Badge created', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeBadge(StoreBadgeRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('manage provider badges'), 403);

        return $this->success(new ProviderBadgeResource($this->recognitionService->createBadge($request->validated())), 'Badge created.', [], 201);
    }

    #[OA\Put(path: '/api/provider-recognition/badges/{badge}', summary: 'Update a provider badge', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Badge updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Validation error')])]
    public function updateBadge(UpdateBadgeRequest $request, ProviderBadge $badge): JsonResponse
    {
        abort_unless($request->user()->can('manage provider badges'), 403);

        return $this->success(new ProviderBadgeResource($this->recognitionService->updateBadge($badge, $request->validated())), 'Badge updated.');
    }

    #[OA\Delete(path: '/api/provider-recognition/badges/{badge}', summary: 'Delete a provider badge', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Badge deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function destroyBadge(Request $request, ProviderBadge $badge): JsonResponse
    {
        abort_unless($request->user()->can('manage provider badges'), 403);
        $this->recognitionService->deleteBadge($badge);

        return $this->success(null, 'Badge deleted.');
    }

    #[OA\Get(path: '/api/provider-recognition/providers', summary: 'List recognition providers', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Providers', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'))])]
    public function providers(ProviderIndexRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('view provider recognition'), 403);

        return $this->paginated($this->recognitionService->providers($request->validated()), RecognitionProviderResource::class, 'Providers retrieved.');
    }

    #[OA\Get(path: '/api/provider-recognition/top-rated', summary: 'List top-rated providers', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Top-rated providers', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'))])]
    public function topRated(ProviderIndexRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('view top rated providers'), 403);

        return $this->paginated($this->recognitionService->topRated($request->validated()), RecognitionProviderResource::class, 'Top-rated providers retrieved.');
    }

    #[OA\Post(path: '/api/provider-recognition/providers/{provider}/badges', summary: 'Assign a badge to a provider', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Badge assigned', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Validation error')])]
    public function assignBadge(AssignBadgeRequest $request, ProviderProfile $provider): JsonResponse
    {
        abort_unless($request->user()->can('assign provider badges'), 403);
        $badge = ProviderBadge::query()->findOrFail($request->integer('badge_id'));

        return $this->success(new RecognitionProviderResource($this->recognitionService->assignBadge($provider, $badge, $request->user())), 'Badge assigned.');
    }

    #[OA\Delete(path: '/api/provider-recognition/providers/{provider}/badges/{badge}', summary: 'Remove a badge from a provider', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Badge removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'))])]
    public function removeBadge(Request $request, ProviderProfile $provider, ProviderBadge $badge): JsonResponse
    {
        abort_unless($request->user()->can('assign provider badges'), 403);

        return $this->success(new RecognitionProviderResource($this->recognitionService->removeBadge($provider, $badge)), 'Badge removed.');
    }

    #[OA\Patch(path: '/api/provider-recognition/providers/{provider}/featured', summary: 'Feature or unfeature a provider', tags: ['Provider Recognition'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Featured status updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'))])]
    public function featured(Request $request, ProviderProfile $provider): JsonResponse
    {
        abort_unless($request->user()->can('manage featured providers'), 403);
        $isFeatured = $request->validate(['is_featured' => ['required', 'boolean']])['is_featured'];

        return $this->success(new RecognitionProviderResource($this->recognitionService->toggleFeatured($provider, $isFeatured)), $isFeatured ? 'Provider featured.' : 'Provider unfeatured.');
    }
}
