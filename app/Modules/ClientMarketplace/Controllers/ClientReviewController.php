<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientMarketplace\Policies\ClientReviewPolicy;
use App\Modules\ClientMarketplace\Requests\ClientReviewIndexRequest;
use App\Modules\ClientMarketplace\Requests\StoreClientReviewRequest;
use App\Modules\ClientMarketplace\Requests\UpdateClientReviewRequest;
use App\Modules\ClientMarketplace\Resources\ClientReviewResource;
use App\Modules\ClientMarketplace\Services\ClientReviewService;
use App\Modules\Reviews\Models\Review;
use App\Shared\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Client Reviews', description: 'Customer reviews for completed bookings')]
class ClientReviewController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ClientReviewService $reviewService,
        private readonly ClientReviewPolicy $reviewPolicy,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/reviews',
        summary: 'List the authenticated customer reviews',
        tags: ['Client Reviews'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))],
        responses: [new OA\Response(response: 200, description: 'Customer reviews', content: new OA\JsonContent(ref: '#/components/schemas/ClientReviewListEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Active, verified client access required'), new OA\Response(response: 422, description: 'Invalid pagination')],
    )]
    public function index(ClientReviewIndexRequest $request): JsonResponse
    {
        $this->ensure($this->reviewPolicy->viewAny($request->user()));

        return $this->paginated(
            $this->reviewService->index($request->user(), $request->validated()),
            ClientReviewResource::class,
            'Reviews retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/reviews',
        summary: 'Review a completed owned booking',
        tags: ['Client Reviews'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['booking_id', 'rating'],
            properties: [
                new OA\Property(property: 'booking_id', type: 'integer'),
                new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5),
                new OA\Property(property: 'comment', type: 'string', nullable: true, maxLength: 2000),
            ],
        )),
        responses: [new OA\Response(response: 201, description: 'Review created', content: new OA\JsonContent(ref: '#/components/schemas/ClientReviewEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Active, verified client access required'), new OA\Response(response: 409, description: 'Booking already reviewed'), new OA\Response(response: 422, description: 'Booking is not completed or validation failed')],
    )]
    public function store(StoreClientReviewRequest $request): JsonResponse
    {
        $this->ensure($this->reviewPolicy->create($request->user()));

        return $this->success(
            new ClientReviewResource($this->reviewService->create($request->user(), $request->validated())),
            'Review created.',
            status: 201,
        );
    }

    #[OA\Put(
        path: '/api/client/v1/reviews/{review}',
        summary: 'Update an owned review',
        tags: ['Client Reviews'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'review', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5), new OA\Property(property: 'comment', type: 'string', nullable: true, maxLength: 2000)])),
        responses: [new OA\Response(response: 200, description: 'Review updated', content: new OA\JsonContent(ref: '#/components/schemas/ClientReviewEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Not owned by the customer'), new OA\Response(response: 404, description: 'Review not found'), new OA\Response(response: 422, description: 'Booking is not completed')],
    )]
    public function update(UpdateClientReviewRequest $request, Review $review): JsonResponse
    {
        $this->ensure($this->reviewPolicy->update($request->user(), $review));

        return $this->success(
            new ClientReviewResource($this->reviewService->update($request->user(), $review, $request->validated())),
            'Review updated.',
        );
    }

    private function ensure(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('You are not authorized to access this customer review.');
        }
    }
}
