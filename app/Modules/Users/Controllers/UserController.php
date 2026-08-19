<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Users\Requests\BanUserRequest;
use App\Modules\Users\Requests\SuspendUserRequest;
use App\Modules\Users\Requests\UnbanUserRequest;
use App\Modules\Users\Requests\UpdateUserRequest;
use App\Modules\Users\Resources\UserManagementResource;
use App\Modules\Users\Services\UserManagementService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * User management endpoints — CRUD plus the moderation lifecycle
 * (suspend / activate / ban / delete).
 *
 * Every action is authorization-gated through the "manage users" gate
 * (super administrators bypass via Gate::before).
 */
#[OA\Tag(name: 'Users', description: 'Manage platform user accounts (view, edit, suspend, activate, ban, delete)')]
class UserController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UserManagementService $userManagementService,
    ) {}

    /**
     * GET /api/users — paginated, searchable, filterable list of platform users.
     */
    #[OA\Get(
        path: '/api/users',
        summary: 'List platform users',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by name, email or user ID', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_type', in: 'query', description: 'Filter by user type', required: false, schema: new OA\Schema(type: 'string', enum: ['customer'])),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by account status', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'suspended', 'banned'])),
            new OA\Parameter(name: 'verification', in: 'query', description: 'Filter by verification status', required: false, schema: new OA\Schema(type: 'string', enum: ['verified', 'unverified'])),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort column', required: false, schema: new OA\Schema(type: 'string', enum: ['name', 'created_at', 'last_login_at'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', description: 'Sort direction', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of users',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Users retrieved.',
                        'data' => [
                            [
                                'id' => 3,
                                'name' => 'Alice Customer',
                                'email' => 'alice@skillserve.test',
                                'roles' => [],
                                'permissions' => [],
                                'first_name' => 'Alice',
                                'last_name' => 'Customer',
                                'user_type' => 'customer',
                                'phone' => '+1 555 0100',
                                'status' => 'active',
                                'verification' => 'verified',
                                'last_login_at' => null,
                                'created_by' => null,
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
                description: 'Missing the manage users permission',
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
        $this->authorize('view users', User::class);

        $paginator = $this->userManagementService->index($request->only([
            'search', 'user_type', 'status', 'verification', 'sort', 'direction', 'per_page',
        ]));

        return $this->paginated($paginator, UserManagementResource::class, 'Users retrieved.');
    }

    /**
     * GET /api/users/{user} — detailed user profile.
     */
    #[OA\Get(
        path: '/api/users/{user}',
        summary: 'Get a user profile',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User profile details',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Request successful.',
                        'data' => [
                            'id' => 3,
                            'name' => 'Alice Customer',
                            'email' => 'alice@skillserve.test',
                            'user_type' => 'customer',
                            'phone' => '+1 555 0100',
                            'address' => '123 Main St',
                            'birthday' => '1995-04-12',
                            'status' => 'active',
                            'verification' => 'verified',
                            'summary' => [
                                'services_count' => 0,
                                'bookings_count' => 0,
                                'ratings_count' => 0,
                                'reviews_count' => 0,
                                'recent_activity' => [],
                            ],
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
                description: 'Missing the manage users permission',
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
                description: 'User not found',
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
    public function show(User $user): JsonResponse
    {
        $this->authorize('view users', User::class);

        $user = $this->userManagementService->show($user);

        return $this->success(new UserManagementResource($user));
    }

    /**
     * PUT/PATCH /api/users/{user} — update a user's profile.
     */
    #[OA\Put(
        path: '/api/users/{user}',
        summary: 'Update a user profile',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                example: [
                    'first_name' => 'Alice',
                    'last_name' => 'Customer',
                    'email' => 'alice@skillserve.test',
                    'phone' => '+1 555 0100',
                    'address' => '123 Main St',
                    'birthday' => '1995-04-12',
                    'user_type' => 'customer',
                ],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Alice'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Customer'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alice@skillserve.test'),
                    new OA\Property(property: 'phone', type: 'string', example: '+1 555 0100', nullable: true),
                    new OA\Property(property: 'address', type: 'string', example: '123 Main St', nullable: true),
                    new OA\Property(property: 'birthday', type: 'string', format: 'date', example: '1995-04-12', nullable: true),
                    new OA\Property(property: 'user_type', type: 'string', enum: ['customer'], example: 'customer'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'User updated.',
                        'data' => [
                            'id' => 3,
                            'name' => 'Alice Customer',
                            'email' => 'alice@skillserve.test',
                            'roles' => [],
                            'permissions' => [],
                            'first_name' => 'Alice',
                            'last_name' => 'Customer',
                            'user_type' => 'customer',
                            'phone' => '+1 555 0100',
                            'status' => 'active',
                            'verification' => 'verified',
                            'last_login_at' => null,
                            'created_by' => null,
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
                description: 'Missing the manage users permission',
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
                description: 'User not found',
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
                        'errors' => ['email' => ['A user with this email already exists.']],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('edit users', User::class);

        $user = $this->userManagementService->update(
            $user,
            $request->validated(),
            $request->user(),
        );

        return $this->success(new UserManagementResource($user), 'User updated.');
    }

    /**
     * PATCH /api/users/{user}/suspend — temporarily suspend a user.
     */
    #[OA\Patch(
        path: '/api/users/{user}/suspend',
        summary: 'Suspend a user account',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                example: ['reason' => 'Suspected fraudulent activity.'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Suspected fraudulent activity.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User suspended',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'User suspended.',
                        'data' => [
                            'id' => 3,
                            'name' => 'Alice Customer',
                            'email' => 'alice@skillserve.test',
                            'roles' => [],
                            'permissions' => [],
                            'first_name' => 'Alice',
                            'last_name' => 'Customer',
                            'user_type' => 'customer',
                            'phone' => '+1 555 0100',
                            'status' => 'suspended',
                            'verification' => 'verified',
                            'last_login_at' => null,
                            'created_by' => null,
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
                description: 'Missing the manage users permission',
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
                description: 'User not found',
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
                description: 'Validation error / account state guard',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The account is already suspended.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function suspend(SuspendUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('suspend users', User::class);

        $user = $this->userManagementService->suspend(
            $user,
            $request->validated('reason'),
            $request->user(),
        );

        return $this->success(new UserManagementResource($user), 'User suspended.');
    }

    /**
     * PATCH /api/users/{user}/activate — restore a suspended user.
     */
    #[OA\Patch(
        path: '/api/users/{user}/activate',
        summary: 'Activate a user account',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User activated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'User activated.',
                        'data' => [
                            'id' => 3,
                            'name' => 'Alice Customer',
                            'email' => 'alice@skillserve.test',
                            'roles' => [],
                            'permissions' => [],
                            'first_name' => 'Alice',
                            'last_name' => 'Customer',
                            'user_type' => 'customer',
                            'phone' => '+1 555 0100',
                            'status' => 'active',
                            'verification' => 'verified',
                            'last_login_at' => null,
                            'created_by' => null,
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
                description: 'Missing the manage users permission',
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
                description: 'User not found',
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
                description: 'Account state guard (e.g. banned accounts are terminal)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'A banned account cannot be activated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorize('activate users', User::class);

        $user = $this->userManagementService->activate($user, $request->user());

        return $this->success(new UserManagementResource($user), 'User activated.');
    }

    /**
     * PATCH /api/users/{user}/ban — ban a user for a number of days or forever.
     */
    #[OA\Patch(
        path: '/api/users/{user}/ban',
        summary: 'Ban a user account (temporary or permanent)',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason', 'duration'],
                example: [
                    'reason' => 'Repeated policy violations.',
                    'duration' => 'days',
                    'days' => 30,
                ],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Repeated policy violations.'),
                    new OA\Property(property: 'duration', type: 'string', enum: ['days', 'forever'], description: 'days = temporary (auto-unban after N days), forever = permanent', example: 'days'),
                    new OA\Property(property: 'days', type: 'integer', minimum: 1, maximum: 3650, description: 'Required when duration is days', example: 30, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User banned',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'User banned.',
                        'data' => [
                            'id' => 3,
                            'name' => 'Alice Customer',
                            'email' => 'alice@skillserve.test',
                            'roles' => [],
                            'permissions' => [],
                            'first_name' => 'Alice',
                            'last_name' => 'Customer',
                            'user_type' => 'customer',
                            'phone' => '+1 555 0100',
                            'status' => 'banned',
                            'verification' => 'verified',
                            'last_login_at' => null,
                            'created_by' => null,
                            'banned_until' => '2026-09-06T08:00:00+00:00',
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
                description: 'Missing the manage users permission',
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
                description: 'User not found',
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
                description: 'Validation error / account state guard',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The account is already banned.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function ban(BanUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('ban users', User::class);

        $user = $this->userManagementService->ban(
            $user,
            $request->user(),
            $request->validated('reason'),
            $request->validated('duration'),
            $request->validated('days'),
        );

        return $this->success(new UserManagementResource($user), 'User banned.');
    }

    /**
     * PATCH /api/users/{user}/unban — lift a ban (temporary or permanent).
     */
    #[OA\Patch(
        path: '/api/users/{user}/unban',
        summary: 'Unban a user account',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                example: ['reason' => 'Ban lifted after review.'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 500, description: 'Optional note recorded in the audit log', example: 'Ban lifted after review.', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User unbanned',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'User unbanned.',
                        'data' => [
                            'id' => 3,
                            'name' => 'Alice Customer',
                            'email' => 'alice@skillserve.test',
                            'roles' => [],
                            'permissions' => [],
                            'first_name' => 'Alice',
                            'last_name' => 'Customer',
                            'user_type' => 'customer',
                            'phone' => '+1 555 0100',
                            'status' => 'active',
                            'verification' => 'verified',
                            'last_login_at' => null,
                            'created_by' => null,
                            'banned_until' => null,
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
                description: 'Missing the manage users permission',
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
                description: 'User not found',
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
                description: 'Account is not currently banned',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The account is not currently banned.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function unban(UnbanUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('ban users', User::class);

        $user = $this->userManagementService->unban(
            $user,
            $request->user(),
            $request->validated('reason'),
        );

        return $this->success(new UserManagementResource($user), 'User unbanned.');
    }

    /**
     * GET /api/users/{user}/moderation-history — full ban/unban audit trail.
     */
    #[OA\Get(
        path: '/api/users/{user}/moderation-history',
        summary: 'Get a user moderation history',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Moderation history (newest first)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Moderation history retrieved.',
                        'data' => [
                            [
                                'id' => 42,
                                'event' => 'user_banned',
                                'logged_at' => '2026-08-07T08:00:00+00:00',
                                'actor' => ['id' => 1, 'name' => 'System Administrator'],
                                'properties' => ['reason' => 'Repeated policy violations.'],
                            ],
                            [
                                'id' => 41,
                                'event' => 'user_unbanned',
                                'logged_at' => '2026-08-01T09:00:00+00:00',
                                'actor' => null,
                                'properties' => ['reason' => 'Temporary ban expired.'],
                            ],
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
                description: 'Missing the manage users permission',
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
                description: 'User not found',
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
    public function moderationHistory(Request $request, User $user): JsonResponse
    {
        $this->authorize('view users', User::class);

        return $this->success(
            $this->userManagementService->moderationHistory($user),
            'Moderation history retrieved.',
        );
    }

    /**
     * DELETE /api/users/{user} — soft-delete a user account.
     */
    #[OA\Delete(
        path: '/api/users/{user}',
        summary: 'Delete a user account',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User deleted (soft delete)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'User deleted.',
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
                description: 'Missing the manage users permission',
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
                description: 'User not found',
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
                description: 'Deletion guard violated (e.g. deleting your own account)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'You cannot delete your own account.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete users', User::class);

        $this->userManagementService->destroy($user, $request->user());

        return $this->success(null, 'User deleted.');
    }
}
