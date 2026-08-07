<?php

namespace App\Modules\Administrators\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administrators\Requests\StoreRoleRequest;
use App\Modules\Administrators\Requests\SyncRolePermissionsRequest;
use App\Modules\Administrators\Requests\UpdateRoleRequest;
use App\Modules\Administrators\Resources\RoleResource;
use App\Modules\Administrators\Services\RoleService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Role;

/**
 * Role management endpoints — CRUD plus permission-matrix sync.
 */
#[OA\Tag(name: 'Roles', description: 'Manage administrative roles and their permissions')]
class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RoleService $roleService,
    ) {}

    /**
     * GET /api/roles — paginated, searchable list of roles.
     */
    #[OA\Get(
        path: '/api/roles',
        summary: 'List roles',
        tags: ['Roles'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by name or description', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort column', required: false, schema: new OA\Schema(type: 'string', enum: ['name', 'created_at'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', description: 'Sort direction', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of roles',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Request successful.',
                        'data' => [
                            [
                                'id' => 3,
                                'name' => 'reports-manager',
                                'description' => 'Manages operational reports.',
                                'guard_name' => 'web',
                                'permissions' => ['view reports'],
                                'created_at' => '2026-08-07T08:00:00+00:00',
                            ],
                        ],
                        'errors' => null,
                        'meta' => ['pagination' => ['total' => 1, 'per_page' => 15, 'current_page' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1]],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Missing the manage administrators permission', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $paginator = $this->roleService->index($request->only([
            'search', 'sort', 'direction', 'per_page',
        ]));

        return $this->paginated($paginator, RoleResource::class, 'Roles retrieved.');
    }

    /**
     * POST /api/roles — create a new role.
     */
    #[OA\Post(
        path: '/api/roles',
        summary: 'Create a role',
        tags: ['Roles'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                example: [
                    'name' => 'reports-manager',
                    'description' => 'Manages operational reports.',
                ],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'reports-manager'),
                    new OA\Property(property: 'description', type: 'string', example: 'Manages operational reports.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Role created',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Role created.',
                        'data' => [
                            'id' => 3,
                            'name' => 'reports-manager',
                            'description' => 'Manages operational reports.',
                            'guard_name' => 'web',
                            'permissions' => [],
                            'created_at' => '2026-08-07T08:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Missing the manage administrators permission', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $role = $this->roleService->store($request->validated(), $request->user());

        return $this->success(new RoleResource($role), 'Role created.', status: 201);
    }

    /**
     * GET /api/roles/{role} — a single role with its permissions.
     */
    #[OA\Get(
        path: '/api/roles/{role}',
        summary: 'Get a role',
        tags: ['Roles'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Role details',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Request successful.',
                        'data' => [
                            'id' => 3,
                            'name' => 'reports-manager',
                            'description' => 'Manages operational reports.',
                            'guard_name' => 'web',
                            'permissions' => ['view reports'],
                            'created_at' => '2026-08-07T08:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Missing the manage administrators permission', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 404, description: 'Role not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        $role = $this->roleService->show($role);

        return $this->success(new RoleResource($role));
    }

    /**
     * PUT/PATCH /api/roles/{role} — update name/description.
     */
    #[OA\Put(
        path: '/api/roles/{role}',
        summary: 'Update a role',
        tags: ['Roles'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                example: [
                    'name' => 'reports-manager',
                    'description' => 'Manages operational and executive reports.',
                ],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'reports-manager'),
                    new OA\Property(property: 'description', type: 'string', example: 'Manages operational and executive reports.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Role updated',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Role updated.',
                        'data' => [
                            'id' => 3,
                            'name' => 'reports-manager',
                            'description' => 'Manages operational and executive reports.',
                            'guard_name' => 'web',
                            'permissions' => ['view reports'],
                            'created_at' => '2026-08-07T08:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Missing the manage administrators permission', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 404, description: 'Role not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $role = $this->roleService->update($role, $request->validated(), $request->user());

        return $this->success(new RoleResource($role), 'Role updated.');
    }

    /**
     * DELETE /api/roles/{role} — delete a role (system role protected).
     */
    #[OA\Delete(
        path: '/api/roles/{role}',
        summary: 'Delete a role',
        tags: ['Roles'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Role deleted',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Role deleted.',
                        'data' => null,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Missing the manage administrators permission', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 404, description: 'Role not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'System default role cannot be deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $this->roleService->destroy($role, $request->user());

        return $this->success(null, 'Role deleted.');
    }

    /**
     * PUT /api/roles/{role}/permissions — sync the permission matrix for a role.
     */
    #[OA\Put(
        path: '/api/roles/{role}/permissions',
        summary: 'Sync role permissions',
        tags: ['Roles'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['permissions'],
                example: [
                    'permissions' => ['view reports', 'manage bookings'],
                ],
                properties: [
                    new OA\Property(
                        property: 'permissions',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        example: ['view reports', 'manage bookings'],
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Role permissions synced',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Role permissions updated.',
                        'data' => [
                            'id' => 3,
                            'name' => 'reports-manager',
                            'description' => 'Manages operational reports.',
                            'guard_name' => 'web',
                            'permissions' => ['view reports', 'manage bookings'],
                            'created_at' => '2026-08-07T08:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Missing the manage administrators permission', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 404, description: 'Role not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error / super administrator permissions immutable', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $this->authorize('syncPermissions', $role);

        $role = $this->roleService->syncPermissions(
            $role,
            $request->validated('permissions'),
            $request->user(),
        );

        return $this->success(new RoleResource($role), 'Role permissions updated.');
    }
}
