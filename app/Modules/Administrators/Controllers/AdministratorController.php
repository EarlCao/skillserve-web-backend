<?php

namespace App\Modules\Administrators\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administrators\Requests\SetAdministratorStatusRequest;
use App\Modules\Administrators\Requests\StoreAdministratorRequest;
use App\Modules\Administrators\Requests\UpdateAdministratorRequest;
use App\Modules\Administrators\Resources\AdministratorResource;
use App\Modules\Administrators\Services\AdministratorService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Administrator management endpoints — CRUD plus activation control.
 *
 * Every action is authorization-gated through the AdministratorPolicy
 * (super administrators bypass via Gate::before).
 */
#[OA\Tag(name: 'Administrators', description: 'Manage administrator accounts (view, create, edit, activate/deactivate)')]
class AdministratorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdministratorService $administratorService,
    ) {}

    /**
     * GET /api/administrators — paginated, searchable, filterable list.
     */
    #[OA\Get(
        path: '/api/administrators',
        summary: 'List administrators',
        tags: ['Administrators'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by name or email', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by activation status', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'role', in: 'query', description: 'Filter by role name', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort column', required: false, schema: new OA\Schema(type: 'string', enum: ['name', 'created_at'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', description: 'Sort direction', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of administrators',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Request successful.',
                        'data' => [
                            [
                                'id' => 2,
                                'first_name' => 'Jane',
                                'last_name' => 'Doe',
                                'name' => 'Jane Doe',
                                'email' => 'jane.doe@skillserve.test',
                                'status' => 'active',
                                'roles' => ['admin'],
                                'permissions' => ['view reports'],
                                'last_login_at' => '2026-08-07T09:30:00+00:00',
                                'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                                'created_at' => '2026-08-07T08:00:00+00:00',
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
                description: 'Missing the manage administrators permission',
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
        $this->authorize('viewAny', User::class);

        $paginator = $this->administratorService->index($request->only([
            'search', 'status', 'role', 'sort', 'direction', 'per_page',
        ]));

        return $this->paginated($paginator, AdministratorResource::class, 'Administrators retrieved.');
    }

    /**
     * POST /api/administrators — create a new administrator account.
     */
    #[OA\Post(
        path: '/api/administrators',
        summary: 'Create an administrator',
        tags: ['Administrators'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['first_name', 'last_name', 'email', 'password', 'role'],
                example: [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'email' => 'jane.doe@skillserve.test',
                    'password' => 'Secret#2026',
                    'password_confirmation' => 'Secret#2026',
                    'role' => 'admin',
                ],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Jane'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Doe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jane.doe@skillserve.test'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                    new OA\Property(property: 'role', type: 'string', example: 'admin'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Administrator created',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Administrator created.',
                        'data' => [
                            'id' => 2,
                            'first_name' => 'Jane',
                            'last_name' => 'Doe',
                            'name' => 'Jane Doe',
                            'email' => 'jane.doe@skillserve.test',
                            'status' => 'active',
                            'roles' => ['admin'],
                            'permissions' => [],
                            'last_login_at' => null,
                            'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'created_at' => '2026-08-07T08:00:00+00:00',
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
                description: 'Missing the manage administrators permission',
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
                description: 'Validation error',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'first_name' => ['The first name field is required.'],
                            'last_name' => ['The last name field is required.'],
                            'email' => ['The email field is required.'],
                            'password' => ['The password field is required.'],
                            'role' => ['The role field is required.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function store(StoreAdministratorRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $administrator = $this->administratorService->store(
            $request->validated(),
            $request->user(),
        );

        return $this->success(new AdministratorResource($administrator), 'Administrator created.', status: 201);
    }

    /**
     * GET /api/administrators/{administrator} — a single administrator.
     */
    #[OA\Get(
        path: '/api/administrators/{administrator}',
        summary: 'Get an administrator',
        tags: ['Administrators'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'administrator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Administrator details',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Request successful.',
                        'data' => [
                            'id' => 2,
                            'first_name' => 'Jane',
                            'last_name' => 'Doe',
                            'name' => 'Jane Doe',
                            'email' => 'jane.doe@skillserve.test',
                            'status' => 'active',
                            'roles' => ['admin'],
                            'permissions' => ['view reports'],
                            'last_login_at' => '2026-08-07T09:30:00+00:00',
                            'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'created_at' => '2026-08-07T08:00:00+00:00',
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
                description: 'Missing the manage administrators permission',
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
                description: 'Administrator not found',
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
    public function show(User $administrator): JsonResponse
    {
        $this->authorize('view', $administrator);

        $administrator = $this->administratorService->show($administrator);

        return $this->success(new AdministratorResource($administrator));
    }

    /**
     * PUT/PATCH /api/administrators/{administrator} — update name, email, role or status.
     */
    #[OA\Put(
        path: '/api/administrators/{administrator}',
        summary: 'Update an administrator',
        tags: ['Administrators'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'administrator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                example: [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'email' => 'jane.doe@skillserve.test',
                    'role' => 'admin',
                    'status' => 'active',
                ],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Jane'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Doe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jane.doe@skillserve.test'),
                    new OA\Property(property: 'role', type: 'string', example: 'admin'),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Administrator updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Administrator updated.',
                        'data' => [
                            'id' => 2,
                            'first_name' => 'Jane',
                            'last_name' => 'Doe',
                            'name' => 'Jane Doe',
                            'email' => 'jane.doe@skillserve.test',
                            'status' => 'active',
                            'roles' => ['admin'],
                            'permissions' => ['view reports'],
                            'last_login_at' => '2026-08-07T09:30:00+00:00',
                            'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'created_at' => '2026-08-07T08:00:00+00:00',
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
                description: 'Missing the manage administrators permission',
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
                description: 'Administrator not found',
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
                description: 'Validation error',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'email' => ['An administrator with this email already exists.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function update(UpdateAdministratorRequest $request, User $administrator): JsonResponse
    {
        $this->authorize('update', $administrator);

        $administrator = $this->administratorService->update(
            $administrator,
            $request->validated(),
            $request->user(),
        );

        return $this->success(new AdministratorResource($administrator), 'Administrator updated.');
    }

    /**
     * PATCH /api/administrators/{administrator}/status — activate or deactivate.
     */
    #[OA\Patch(
        path: '/api/administrators/{administrator}/status',
        summary: 'Activate or deactivate an administrator',
        tags: ['Administrators'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'administrator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                example: ['status' => 'inactive'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'inactive'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Administrator status updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Administrator status updated.',
                        'data' => [
                            'id' => 2,
                            'first_name' => 'Jane',
                            'last_name' => 'Doe',
                            'name' => 'Jane Doe',
                            'email' => 'jane.doe@skillserve.test',
                            'status' => 'inactive',
                            'roles' => ['admin'],
                            'permissions' => ['view reports'],
                            'last_login_at' => '2026-08-07T09:30:00+00:00',
                            'created_by' => ['id' => 1, 'name' => 'System Administrator'],
                            'created_at' => '2026-08-07T08:00:00+00:00',
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
                description: 'Missing the manage administrators permission',
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
                description: 'Administrator not found',
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
                description: 'Validation error / guard rule violated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'You cannot deactivate your own account.',
                        'data' => new \stdClass,
                        'errors' => [
                            'status' => ['You cannot deactivate your own account.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function updateStatus(SetAdministratorStatusRequest $request, User $administrator): JsonResponse
    {
        $this->authorize('updateStatus', $administrator);

        $administrator = $this->administratorService->updateStatus(
            $administrator,
            $request->validated('status'),
            $request->user(),
        );

        return $this->success(new AdministratorResource($administrator), 'Administrator status updated.');
    }
}
