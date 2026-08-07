<?php

namespace App\Modules\Administrators\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administrators\Services\PermissionService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Permission;

/**
 * Permission matrix endpoint. The matrix is read-only — permissions are
 * assigned to roles through RoleController::syncPermissions.
 */
#[OA\Tag(name: 'Permissions', description: 'View the permission catalog grouped by module (permission matrix)')]
class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    /**
     * GET /api/permissions — every permission grouped by module.
     */
    #[OA\Get(
        path: '/api/permissions',
        summary: 'List permissions grouped by module',
        tags: ['Permissions'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Permission matrix',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Request successful.',
                        'data' => [
                            [
                                'module' => 'Administrators',
                                'permissions' => [
                                    ['id' => 1, 'name' => 'manage administrators', 'module' => 'Administrators', 'guard_name' => 'web'],
                                ],
                            ],
                            [
                                'module' => 'Reports',
                                'permissions' => [
                                    ['id' => 5, 'name' => 'view reports', 'module' => 'Reports', 'guard_name' => 'web'],
                                ],
                            ],
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Missing the manage administrators permission', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        return $this->success($this->permissionService->matrix(), 'Permissions retrieved.');
    }
}
