<?php

namespace App\Modules\Audit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Requests\AuditIndexRequest;
use App\Modules\Audit\Resources\AuditLogResource;
use App\Modules\Audit\Services\AuditLogService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Security and Audit', description: 'Search and monitor administrator audit activity')]
class AuditLogController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLogService) {}

    #[OA\Get(path: '/api/audit-logs', summary: 'List audit logs', tags: ['Security and Audit'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Paginated audit logs', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function index(AuditIndexRequest $request): JsonResponse
    {
        $view = $request->validated('view', 'all');

        abort_unless($request->user()->can(match ($view) {
            'login' => 'view login activity',
            'security' => 'monitor security events',
            default => 'view audit logs',
        }), 403);

        return $this->paginated(
            $this->auditLogService->index($request->validated()),
            AuditLogResource::class,
            'Audit logs retrieved.',
        );
    }

    #[OA\Get(path: '/api/audit-logs/administrators', summary: 'List audit log administrators', tags: ['Security and Audit'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function administrators(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can('view audit logs')
                || $request->user()->can('view login activity')
                || $request->user()->can('monitor security events'),
            403,
        );

        return $this->success($this->auditLogService->administrators(), 'Audit administrators retrieved.');
    }
}
