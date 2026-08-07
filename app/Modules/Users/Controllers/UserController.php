<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Users\Requests\BanUserRequest;
use App\Modules\Users\Requests\SuspendUserRequest;
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
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage users permission',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('manage users', User::class);

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
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage users permission',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 404,
                description: 'User not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
        ],
    )]
    public function show(User $user): JsonResponse
    {
        $this->authorize('manage users', User::class);

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
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage users permission',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 404,
                description: 'User not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
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
        $this->authorize('manage users', User::class);

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
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage users permission',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 404,
                description: 'User not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error / account state guard',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
        ],
    )]
    public function suspend(SuspendUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('manage users', User::class);

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
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage users permission',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 404,
                description: 'User not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 422,
                description: 'Account state guard (e.g. banned accounts are terminal)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
        ],
    )]
    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage users', User::class);

        $user = $this->userManagementService->activate($user, $request->user());

        return $this->success(new UserManagementResource($user), 'User activated.');
    }

    /**
     * PATCH /api/users/{user}/ban — permanently ban a user.
     */
    #[OA\Patch(
        path: '/api/users/{user}/ban',
        summary: 'Ban a user account',
        tags: ['Users'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                example: ['reason' => 'Repeated policy violations.'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Repeated policy violations.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User banned',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage users permission',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 404,
                description: 'User not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error / account state guard',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
        ],
    )]
    public function ban(BanUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('manage users', User::class);

        $user = $this->userManagementService->ban(
            $user,
            $request->validated('reason'),
            $request->user(),
        );

        return $this->success(new UserManagementResource($user), 'User banned.');
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
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage users permission',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 404,
                description: 'User not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
            new OA\Response(
                response: 422,
                description: 'Deletion guard violated (e.g. deleting your own account)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'),
            ),
        ],
    )]
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage users', User::class);

        $this->userManagementService->destroy($user, $request->user());

        return $this->success(null, 'User deleted.');
    }
}
