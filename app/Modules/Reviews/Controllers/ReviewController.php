<?php

namespace App\Modules\Reviews\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Requests\HideReviewRequest;
use App\Modules\Reviews\Resources\ReviewResource;
use App\Modules\Reviews\Services\ReviewService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Reviews', description: 'Manage reviews and ratings (view, hide, restore, remove)')]
class ReviewController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReviewService $reviewService,
    ) {}

    #[OA\Get(
        path: '/api/reviews',
        summary: 'List reviews',
        tags: ['Reviews'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by comment, reviewer, provider, or service', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'rating', in: 'query', description: 'Filter by rating (1-5)', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by status', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'hidden', 'removed'])),
            new OA\Parameter(name: 'is_reported', in: 'query', description: 'Filter by reported status', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'provider_id', in: 'query', description: 'Filter by provider profile ID', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'service_id', in: 'query', description: 'Filter by service ID', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort column', required: false, schema: new OA\Schema(type: 'string', enum: ['rating', 'created_at'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', description: 'Sort direction', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of reviews',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Review::class);

        $paginator = $this->reviewService->index($request->only([
            'search', 'rating', 'status', 'is_reported',
            'provider_id', 'service_id', 'sort', 'direction', 'per_page',
        ]));

        return $this->paginated($paginator, ReviewResource::class, 'Reviews retrieved.');
    }

    #[OA\Get(
        path: '/api/reviews/{review}',
        summary: 'Get a review with its relationships',
        tags: ['Reviews'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Review details',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Review $review): JsonResponse
    {
        $this->authorize('view', $review);

        $review = $this->reviewService->show($review);

        return $this->success(new ReviewResource($review));
    }

    #[OA\Patch(
        path: '/api/reviews/{review}/hide',
        summary: 'Hide or restore a review',
        tags: ['Reviews'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['is_hidden'],
                example: ['is_hidden' => true],
                properties: [
                    new OA\Property(property: 'is_hidden', type: 'boolean', example: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Review visibility updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function hide(HideReviewRequest $request, Review $review): JsonResponse
    {
        $this->authorize('hide', $review);

        $review = $this->reviewService->toggleHide($review, $request->boolean('is_hidden'), $request->user());

        return $this->success(
            new ReviewResource($review),
            $review->isHidden() ? 'Review hidden.' : 'Review restored.',
        );
    }

    #[OA\Delete(
        path: '/api/reviews/{review}',
        summary: 'Permanently remove a review',
        tags: ['Reviews'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Review removed',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function destroy(Request $request, Review $review): JsonResponse
    {
        $this->authorize('delete', $review);

        $this->reviewService->destroy($review, $request->user());

        return $this->success(null, 'Review removed.');
    }
}
