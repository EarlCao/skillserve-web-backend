<?php

namespace App\Modules\ServiceCategories\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Modules\ServiceCategories\Requests\SetServiceCategoryStatusRequest;
use App\Modules\ServiceCategories\Requests\StoreServiceCategoryRequest;
use App\Modules\ServiceCategories\Requests\StoreServiceSubcategoryRequest;
use App\Modules\ServiceCategories\Requests\UpdateServiceCategoryRequest;
use App\Modules\ServiceCategories\Requests\UpdateServiceSubcategoryRequest;
use App\Modules\ServiceCategories\Resources\ServiceCategoryResource;
use App\Modules\ServiceCategories\Resources\ServiceSubcategoryResource;
use App\Modules\ServiceCategories\Services\ServiceCategoryService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Service category management endpoints — CRUD plus enable/disable and the
 * nested subcategory CRUD.
 *
 * Every action is authorization-gated through the ServiceCategoryPolicy
 * ("manage service categories" permission; super administrators bypass via
 * Gate::before).
 */
#[OA\Tag(name: 'Service Categories', description: 'Manage service categories and their subcategories (view, create, edit, delete, enable/disable)')]
class ServiceCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ServiceCategoryService $serviceCategoryService,
    ) {}

    /**
     * GET /api/service-categories — paginated, searchable, filterable list.
     */
    #[OA\Get(
        path: '/api/service-categories',
        summary: 'List service categories',
        tags: ['Service Categories'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by name or description', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by enabled state', required: false, schema: new OA\Schema(type: 'string', enum: ['enabled', 'disabled'])),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort column', required: false, schema: new OA\Schema(type: 'string', enum: ['name', 'created_at'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', description: 'Sort direction', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of service categories',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service categories retrieved.',
                        'data' => [
                            [
                                'id' => 1,
                                'name' => 'Home Maintenance',
                                'description' => 'Plumbing, electrical and painting services.',
                                'status' => 'enabled',
                                'subcategories_count' => 3,
                                'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                                'created_at' => '2026-08-12T08:00:00+00:00',
                                'updated_at' => '2026-08-12T08:00:00+00:00',
                            ],
                        ],
                        'errors' => null,
                        'meta' => ['pagination' => ['total' => 1, 'per_page' => 15, 'current_page' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1]],
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
                description: 'Missing the manage service categories permission',
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
        $this->authorize('viewAny', ServiceCategory::class);

        $paginator = $this->serviceCategoryService->index($request->only([
            'search', 'status', 'sort', 'direction', 'per_page',
        ]));

        return $this->paginated($paginator, ServiceCategoryResource::class, 'Service categories retrieved.');
    }

    /**
     * POST /api/service-categories — create a new service category.
     */
    #[OA\Post(
        path: '/api/service-categories',
        summary: 'Create a service category',
        tags: ['Service Categories'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                example: [
                    'name' => 'Home Maintenance',
                    'description' => 'Plumbing, electrical and painting services.',
                ],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Home Maintenance'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 1000, example: 'Plumbing, electrical and painting services.', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['enabled', 'disabled'], default: 'enabled'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Service category created',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service category created.',
                        'data' => [
                            'id' => 1,
                            'name' => 'Home Maintenance',
                            'description' => 'Plumbing, electrical and painting services.',
                            'status' => 'enabled',
                            'subcategories_count' => 0,
                            'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'created_at' => '2026-08-12T08:00:00+00:00',
                            'updated_at' => '2026-08-12T08:00:00+00:00',
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
                description: 'Missing the manage service categories permission',
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
                response: 422,
                description: 'Validation error (e.g. duplicate name)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'name' => ['A service category with this name already exists.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', ServiceCategory::class);

        $category = $this->serviceCategoryService->store($request->validated(), $request->user());

        return $this->success(new ServiceCategoryResource($category), 'Service category created.', status: 201);
    }

    /**
     * GET /api/service-categories/{serviceCategory} — a category with its subcategories.
     */
    #[OA\Get(
        path: '/api/service-categories/{serviceCategory}',
        summary: 'Get a service category with its subcategories',
        tags: ['Service Categories'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceCategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service category details with nested subcategories',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Request successful.',
                        'data' => [
                            'id' => 1,
                            'name' => 'Home Maintenance',
                            'description' => 'Plumbing, electrical and painting services.',
                            'status' => 'enabled',
                            'subcategories' => [
                                [
                                    'id' => 1,
                                    'category_id' => 1,
                                    'name' => 'Plumbing',
                                    'description' => null,
                                    'status' => 'enabled',
                                    'created_at' => '2026-08-12T08:00:00+00:00',
                                    'updated_at' => '2026-08-12T08:00:00+00:00',
                                ],
                            ],
                            'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'created_at' => '2026-08-12T08:00:00+00:00',
                            'updated_at' => '2026-08-12T08:00:00+00:00',
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
                description: 'Missing the manage service categories permission',
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
                description: 'Service category not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function show(ServiceCategory $serviceCategory): JsonResponse
    {
        $this->authorize('view', $serviceCategory);

        $category = $this->serviceCategoryService->show($serviceCategory);

        return $this->success(new ServiceCategoryResource($category));
    }

    /**
     * PUT/PATCH /api/service-categories/{serviceCategory} — update name/description.
     */
    #[OA\Put(
        path: '/api/service-categories/{serviceCategory}',
        summary: 'Update a service category',
        tags: ['Service Categories'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceCategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                example: [
                    'name' => 'Home Maintenance & Repair',
                    'description' => 'Plumbing, electrical, painting and repair services.',
                ],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Home Maintenance & Repair'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 1000, example: 'Plumbing, electrical, painting and repair services.', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['enabled', 'disabled']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service category updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service category updated.',
                        'data' => [
                            'id' => 1,
                            'name' => 'Home Maintenance & Repair',
                            'description' => 'Plumbing, electrical, painting and repair services.',
                            'status' => 'enabled',
                            'subcategories_count' => 3,
                            'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'created_at' => '2026-08-12T08:00:00+00:00',
                            'updated_at' => '2026-08-12T09:00:00+00:00',
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
                description: 'Missing the manage service categories permission',
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
                description: 'Service category not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error (e.g. duplicate name)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'name' => ['A service category with this name already exists.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $this->authorize('update', $serviceCategory);

        $category = $this->serviceCategoryService->update(
            $serviceCategory,
            $request->validated(),
            $request->user(),
        );

        return $this->success(new ServiceCategoryResource($category), 'Service category updated.');
    }

    /**
     * PATCH /api/service-categories/{serviceCategory}/status — enable or disable.
     */
    #[OA\Patch(
        path: '/api/service-categories/{serviceCategory}/status',
        summary: 'Enable or disable a service category',
        description: 'Disabled categories remain in the database but are no longer selectable or displayed on the platform. They are never physically deleted by this operation.',
        tags: ['Service Categories'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceCategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                example: ['status' => 'disabled'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['enabled', 'disabled'], example: 'disabled'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service category status updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service category status updated.',
                        'data' => [
                            'id' => 1,
                            'name' => 'Home Maintenance',
                            'description' => 'Plumbing, electrical and painting services.',
                            'status' => 'disabled',
                            'subcategories_count' => 3,
                            'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'created_at' => '2026-08-12T08:00:00+00:00',
                            'updated_at' => '2026-08-12T09:00:00+00:00',
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
                description: 'Missing the manage service categories permission',
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
                description: 'Service category not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error / invalid status value',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'status' => ['The selected status is invalid.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function updateStatus(SetServiceCategoryStatusRequest $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $this->authorize('updateStatus', $serviceCategory);

        $category = $this->serviceCategoryService->updateStatus(
            $serviceCategory,
            $request->validated('status'),
            $request->user(),
        );

        return $this->success(new ServiceCategoryResource($category), 'Service category status updated.');
    }

    /**
     * DELETE /api/service-categories/{serviceCategory} — soft-delete a category.
     */
    #[OA\Delete(
        path: '/api/service-categories/{serviceCategory}',
        summary: 'Delete a service category',
        description: 'Soft-deletes the category. A category that still has subcategories cannot be deleted — remove its subcategories first.',
        tags: ['Service Categories'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceCategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service category deleted (soft delete)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Service category deleted.',
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
                description: 'Missing the manage service categories permission',
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
                description: 'Service category not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Deletion guard violated (category still has subcategories)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This category still has subcategories. Delete its subcategories first.',
                        'data' => new \stdClass,
                        'errors' => [
                            'subcategories' => ['This category still has subcategories. Delete its subcategories first.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function destroy(Request $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $this->authorize('delete', $serviceCategory);

        $this->serviceCategoryService->destroy($serviceCategory, $request->user());

        return $this->success(null, 'Service category deleted.');
    }

    /**
     * POST /api/service-categories/{serviceCategory}/subcategories — create a subcategory.
     */
    #[OA\Post(
        path: '/api/service-categories/{serviceCategory}/subcategories',
        summary: 'Create a subcategory',
        tags: ['Service Categories'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceCategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                example: [
                    'name' => 'Plumbing',
                    'description' => 'Pipe installation, repair and maintenance.',
                ],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Plumbing'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 1000, example: 'Pipe installation, repair and maintenance.', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['enabled', 'disabled'], default: 'enabled'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Subcategory created',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Subcategory created.',
                        'data' => [
                            'id' => 1,
                            'category_id' => 1,
                            'name' => 'Plumbing',
                            'description' => 'Pipe installation, repair and maintenance.',
                            'status' => 'enabled',
                            'created_at' => '2026-08-12T08:00:00+00:00',
                            'updated_at' => '2026-08-12T08:00:00+00:00',
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
                description: 'Missing the manage service categories permission',
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
                description: 'Parent service category not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error (e.g. duplicate name within the category)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'name' => ['A subcategory with this name already exists in this category.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function storeSubcategory(StoreServiceSubcategoryRequest $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $this->authorize('update', $serviceCategory);

        $subcategory = $this->serviceCategoryService->storeSubcategory(
            $serviceCategory,
            $request->validated(),
            $request->user(),
        );

        return $this->success(new ServiceSubcategoryResource($subcategory), 'Subcategory created.', status: 201);
    }

    /**
     * PUT/PATCH /api/service-categories/{serviceCategory}/subcategories/{serviceSubcategory} — update a subcategory.
     */
    #[OA\Put(
        path: '/api/service-categories/{serviceCategory}/subcategories/{serviceSubcategory}',
        summary: 'Update a subcategory',
        description: 'The subcategory must belong to the parent category in the URL.',
        tags: ['Service Categories'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceCategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'serviceSubcategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                example: [
                    'name' => 'Emergency Plumbing',
                    'description' => '24/7 emergency pipe repair.',
                    'status' => 'enabled',
                ],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Emergency Plumbing'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 1000, example: '24/7 emergency pipe repair.', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['enabled', 'disabled']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Subcategory updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Subcategory updated.',
                        'data' => [
                            'id' => 1,
                            'category_id' => 1,
                            'name' => 'Emergency Plumbing',
                            'description' => '24/7 emergency pipe repair.',
                            'status' => 'enabled',
                            'created_at' => '2026-08-12T08:00:00+00:00',
                            'updated_at' => '2026-08-12T09:00:00+00:00',
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
                description: 'Missing the manage service categories permission',
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
                description: 'Category or subcategory not found (or subcategory does not belong to the category)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error (e.g. duplicate name within the category)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'name' => ['A subcategory with this name already exists in this category.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function updateSubcategory(
        UpdateServiceSubcategoryRequest $request,
        ServiceCategory $serviceCategory,
        ServiceSubcategory $serviceSubcategory,
    ): JsonResponse {
        $this->authorize('update', $serviceCategory);

        $subcategory = $this->serviceCategoryService->updateSubcategory(
            $serviceCategory,
            $serviceSubcategory,
            $request->validated(),
            $request->user(),
        );

        return $this->success(new ServiceSubcategoryResource($subcategory), 'Subcategory updated.');
    }

    /**
     * DELETE /api/service-categories/{serviceCategory}/subcategories/{serviceSubcategory} — delete a subcategory.
     */
    #[OA\Delete(
        path: '/api/service-categories/{serviceCategory}/subcategories/{serviceSubcategory}',
        summary: 'Delete a subcategory',
        description: 'Soft-deletes the subcategory. The subcategory must belong to the parent category in the URL.',
        tags: ['Service Categories'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceCategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'serviceSubcategory', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Subcategory deleted (soft delete)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Subcategory deleted.',
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
                description: 'Missing the manage service categories permission',
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
                description: 'Category or subcategory not found (or subcategory does not belong to the category)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Deletion guard violated (subcategory already deleted)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The subcategory is already deleted.',
                        'data' => new \stdClass,
                        'errors' => [
                            'id' => ['The subcategory is already deleted.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function destroySubcategory(
        Request $request,
        ServiceCategory $serviceCategory,
        ServiceSubcategory $serviceSubcategory,
    ): JsonResponse {
        $this->authorize('update', $serviceCategory);

        $this->serviceCategoryService->destroySubcategory(
            $serviceCategory,
            $serviceSubcategory,
            $request->user(),
        );

        return $this->success(null, 'Subcategory deleted.');
    }
}
