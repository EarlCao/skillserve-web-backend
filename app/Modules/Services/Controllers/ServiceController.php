<?php

namespace App\Modules\Services\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Requests\ApproveServiceRequest;
use App\Modules\Services\Requests\RejectServiceRequest;
use App\Modules\Services\Requests\UpdateServiceRequest;
use App\Modules\Services\Resources\ServiceResource;
use App\Modules\Services\Services\ServiceService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Service moderation endpoints — list, view, edit listing details, approve,
 * reject, hide, feature, and delete. Providers create services and set
 * pricing through the client API; every action here notifies the provider.
 *
 * Every action is authorization-gated through the ServicePolicy
 * ("manage services" permission; super administrators bypass via
 * Gate::before).
 */
#[OA\Tag(name: 'Services', description: 'Moderate provider services (view, edit, approve, reject, hide, feature, delete)')]
class ServiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ServiceService $serviceService,
    ) {}

    /**
     * GET /api/services — paginated, searchable, filterable list.
     */
    #[OA\Get(
        path: '/api/services',
        summary: 'List services',
        tags: ['Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by title, description, provider, or category', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by status', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'published', 'archived'])),
            new OA\Parameter(name: 'approval_status', in: 'query', description: 'Filter by approval status', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected'])),
            new OA\Parameter(name: 'category_id', in: 'query', description: 'Filter by category ID', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'provider_id', in: 'query', description: 'Filter by provider profile ID', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'is_featured', in: 'query', description: 'Filter by featured status', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'is_hidden', in: 'query', description: 'Filter by hidden status', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort column', required: false, schema: new OA\Schema(type: 'string', enum: ['title', 'created_at', 'average_rating', 'price'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', description: 'Sort direction', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of services',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Services retrieved.',
                        'data' => [
                            [
                                'id' => 1,
                                'title' => 'Emergency Pipe Repair',
                                'description' => 'Fast, reliable emergency pipe repair.',
                                'price' => 150.00,
                                'price_type' => 'fixed',
                                'currency' => 'PHP',
                                'duration' => '1-2 hours',
                                'location' => 'Austin, TX',
                                'status' => 'published',
                                'approval_status' => 'approved',
                                'rejection_reason' => null,
                                'is_featured' => true,
                                'is_hidden' => false,
                                'total_bookings' => 310,
                                'completed_bookings' => 298,
                                'average_rating' => 4.80,
                                'total_reviews' => 92,
                                'provider' => [
                                    'id' => 1,
                                    'business_name' => 'Garcia Plumbing Solutions',
                                    'user' => ['id' => 1, 'name' => 'Maria Garcia', 'email' => 'maria.garcia@example.com'],
                                ],
                                'category' => ['id' => 1, 'name' => 'Home Maintenance'],
                                'subcategory' => ['id' => 1, 'name' => 'Plumbing'],
                                'approved_by' => ['id' => 1, 'name' => 'System Administrator'],
                                'approved_at' => '2026-08-15T10:00:00+00:00',
                                'created_at' => '2026-08-10T08:00:00+00:00',
                                'updated_at' => '2026-08-15T10:00:00+00:00',
                            ],
                        ],
                        'errors' => null,
                        'meta' => ['pagination' => ['total' => 40, 'per_page' => 15, 'current_page' => 1, 'last_page' => 3, 'from' => 1, 'to' => 15]],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the view services permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Service::class);

        $paginator = $this->serviceService->index($request->only([
            'search', 'status', 'approval_status', 'category_id', 'provider_id',
            'is_featured', 'is_hidden', 'sort', 'direction', 'per_page',
        ]));

        return $this->paginated($paginator, ServiceResource::class, 'Services retrieved.');
    }

    /**
     * GET /api/services/{service} — a single service with relationships.
     */
    #[OA\Get(
        path: '/api/services/{service}',
        summary: 'Get a service with its relationships',
        tags: ['Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service details',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Request successful.',
                        'data' => [
                            'id' => 1,
                            'title' => 'Emergency Pipe Repair',
                            'description' => 'Fast, reliable emergency pipe repair.',
                            'price' => 150.00,
                            'price_type' => 'fixed',
                            'currency' => 'PHP',
                            'duration' => '1-2 hours',
                            'location' => 'Austin, TX',
                            'status' => 'published',
                            'approval_status' => 'approved',
                            'rejection_reason' => null,
                            'is_featured' => true,
                            'is_hidden' => false,
                            'total_bookings' => 310,
                            'completed_bookings' => 298,
                            'average_rating' => 4.80,
                            'total_reviews' => 92,
                            'provider' => ['id' => 1, 'business_name' => 'Garcia Plumbing Solutions', 'user' => ['id' => 1, 'name' => 'Maria Garcia', 'email' => 'maria.garcia@example.com']],
                            'category' => ['id' => 1, 'name' => 'Home Maintenance'],
                            'subcategory' => ['id' => 1, 'name' => 'Plumbing'],
                            'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'updated_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'approved_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'approved_at' => '2026-08-15T10:00:00+00:00',
                            'created_at' => '2026-08-10T08:00:00+00:00',
                            'updated_at' => '2026-08-15T10:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the view services permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Service not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Service not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function show(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        $service = $this->serviceService->show($service);

        return $this->success(new ServiceResource($service));
    }

    /**
     * PUT/PATCH /api/services/{service} — update service information.
     */
    #[OA\Put(
        path: '/api/services/{service}',
        summary: 'Update a service',
        tags: ['Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Administrators may correct listing details only. Provider, pricing, duration and location belong to the provider and are rejected with 422.',
            content: new OA\JsonContent(
                example: [
                    'title' => 'Updated Service Title',
                    'description' => 'Updated description.',
                    'category_id' => 1,
                ],
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', maxLength: 5000, nullable: true),
                    new OA\Property(property: 'category_id', type: 'integer'),
                    new OA\Property(property: 'subcategory_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'archived']),
                    new OA\Property(property: 'is_featured', type: 'boolean'),
                    new OA\Property(property: 'is_hidden', type: 'boolean'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service updated.',
                        'data' => [
                            'id' => 1,
                            'title' => 'Updated Service Title',
                            'description' => 'Updated description.',
                            'price' => 1500.00,
                            'price_type' => 'fixed',
                            'currency' => 'PHP',
                            'status' => 'published',
                            'approval_status' => 'approved',
                            'is_featured' => true,
                            'is_hidden' => false,
                            'created_at' => '2026-08-10T08:00:00+00:00',
                            'updated_at' => '2026-08-20T12:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the edit services permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Service not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Service not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Validation failed.',
                        'data' => new \stdClass,
                        'errors' => [
                            'price' => ['The price field is prohibited.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $this->authorize('update', $service);

        $service = $this->serviceService->update($service, $request->validated(), $request->user());

        return $this->success(new ServiceResource($service), 'Service updated.');
    }

    /**
     * PATCH /api/services/{service}/approve — approve a service.
     */
    #[OA\Patch(
        path: '/api/services/{service}/approve',
        summary: 'Approve a service',
        tags: ['Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                example: [
                    'notes' => 'Service meets all quality standards.',
                ],
                properties: [
                    new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service approved',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service approved.',
                        'data' => [
                            'id' => 1,
                            'title' => 'Emergency Pipe Repair',
                            'approval_status' => 'approved',
                            'status' => 'published',
                            'approved_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'approved_at' => '2026-08-20T12:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the approve services permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Service not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Service not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function approve(ApproveServiceRequest $request, Service $service): JsonResponse
    {
        $this->authorize('approve', $service);

        $service = $this->serviceService->approve($service, $request->user(), $request->validated('notes'));

        return $this->success(new ServiceResource($service), 'Service approved.');
    }

    /**
     * PATCH /api/services/{service}/reject — reject a service.
     */
    #[OA\Patch(
        path: '/api/services/{service}/reject',
        summary: 'Reject a service',
        tags: ['Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                example: [
                    'reason' => 'Service description is incomplete.',
                ],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 1000, example: 'Service description is incomplete.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service rejected',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service rejected.',
                        'data' => [
                            'id' => 1,
                            'title' => 'Emergency Pipe Repair',
                            'approval_status' => 'rejected',
                            'status' => 'draft',
                            'rejection_reason' => 'Service description is incomplete.',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the reject services permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Service not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Service not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error (reason required)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Validation failed.',
                        'data' => new \stdClass,
                        'errors' => [
                            'reason' => ['The reason field is required.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function reject(RejectServiceRequest $request, Service $service): JsonResponse
    {
        $this->authorize('reject', $service);

        $service = $this->serviceService->reject($service, $request->user(), $request->validated('reason'));

        return $this->success(new ServiceResource($service), 'Service rejected.');
    }

    /**
     * PATCH /api/services/{service}/hide — hide/unhide a service.
     */
    #[OA\Patch(
        path: '/api/services/{service}/hide',
        summary: 'Hide or unhide a service',
        tags: ['Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['is_hidden'],
                example: [
                    'is_hidden' => true,
                ],
                properties: [
                    new OA\Property(property: 'is_hidden', type: 'boolean', example: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service visibility updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service hidden.',
                        'data' => [
                            'id' => 1,
                            'title' => 'Emergency Pipe Repair',
                            'is_hidden' => true,
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the edit services permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Service not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Service not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function hide(Request $request, Service $service): JsonResponse
    {
        $this->authorize('hide', $service);

        $request->validate([
            'is_hidden' => ['required', 'boolean'],
        ]);

        $service = $this->serviceService->toggleHide($service, $request->boolean('is_hidden'), $request->user());

        return $this->success(new ServiceResource($service), $service->is_hidden ? 'Service hidden.' : 'Service visible.');
    }

    /**
     * PATCH /api/services/{service}/feature — feature/unfeature a service.
     */
    #[OA\Patch(
        path: '/api/services/{service}/feature',
        summary: 'Feature or unfeature a service',
        tags: ['Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['is_featured'],
                example: [
                    'is_featured' => true,
                ],
                properties: [
                    new OA\Property(property: 'is_featured', type: 'boolean', example: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service featured status updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service featured.',
                        'data' => [
                            'id' => 1,
                            'title' => 'Emergency Pipe Repair',
                            'is_featured' => true,
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the feature services permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Service not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Service not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function feature(Request $request, Service $service): JsonResponse
    {
        $this->authorize('feature', $service);

        $request->validate([
            'is_featured' => ['required', 'boolean'],
        ]);

        $service = $this->serviceService->toggleFeature($service, $request->boolean('is_featured'), $request->user());

        return $this->success(new ServiceResource($service), $service->is_featured ? 'Service featured.' : 'Service unfeatured.');
    }

    /**
     * DELETE /api/services/{service} — soft-delete a service.
     */
    #[OA\Delete(
        path: '/api/services/{service}',
        summary: 'Delete a service',
        description: 'Soft-deletes the service.',
        tags: ['Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service deleted (soft delete)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service deleted.',
                        'data' => null,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the delete services permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Service not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Service not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function destroy(Request $request, Service $service): JsonResponse
    {
        $this->authorize('delete', $service);

        $this->serviceService->destroy($service, $request->user());

        return $this->success(null, 'Service deleted.');
    }
}
