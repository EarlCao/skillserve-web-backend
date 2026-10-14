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
        summary: 'List permissions grouped by admin area',
        description: 'One group per area of the admin web (config/permission-areas.php). `view_permissions` is the read-only level; `permissions` is everything full control grants, view first. Permissions in no area are listed under key `other`.',
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
                                'key' => 'settings',
                                'module' => 'System settings',
                                'description' => 'Platform rules, policies and appearance.',
                                'view_permissions' => ['view settings'],
                                'permissions' => [
                                    ['id' => 90, 'name' => 'view settings', 'module' => 'Settings', 'guard_name' => 'web'],
                                    ['id' => 91, 'name' => 'manage settings', 'module' => 'Settings', 'guard_name' => 'web'],
                                ],
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
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        return $this->success($this->permissionService->matrix(), 'Permissions retrieved.');
    }
}
