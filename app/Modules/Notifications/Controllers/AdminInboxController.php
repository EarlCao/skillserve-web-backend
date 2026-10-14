<?php

namespace App\Modules\Notifications\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Requests\AdminInboxIndexRequest;
use App\Modules\Notifications\Resources\AdminAlertResource;
use App\Modules\Notifications\Services\AdminAlertService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Admin Inbox', description: 'Alerts for administrators: verification requests, services to approve, reports, disputes, support requests and commission payments to check. Each administrator receives only the alerts their permissions cover.')]
class AdminInboxController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AdminAlertService $alertService) {}

    #[OA\Get(
        path: '/api/inbox',
        summary: 'List the signed-in administrator\'s alerts, newest first',
        tags: ['Admin Inbox'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['all', 'unread'], default: 'all')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated alerts', content: new OA\JsonContent(ref: '#/components/schemas/AdminAlertListEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Administrators only'),
            new OA\Response(response: 422, description: 'Invalid filter or pagination'),
        ],
    )]
    public function index(AdminInboxIndexRequest $request): JsonResponse
    {
        $this->authorize('use admin inbox');

        return $this->paginated(
            $this->alertService->index($request->user(), $request->validated()),
            AdminAlertResource::class,
            'Alerts retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/inbox/unread-count',
        summary: 'Count the signed-in administrator\'s unread alerts',
        tags: ['Admin Inbox'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Unread count', content: new OA\JsonContent(ref: '#/components/schemas/ClientUnreadCountEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Administrators only'),
        ],
    )]
    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorize('use admin inbox');

        return $this->success([
            'unread_count' => $this->alertService->unreadCount($request->user()),
        ], 'Unread alert count retrieved.');
    }

    #[OA\Patch(
        path: '/api/inbox/{alert}/read',
        summary: 'Mark one of the administrator\'s own alerts as read',
        tags: ['Admin Inbox'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'alert', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Alert marked read', content: new OA\JsonContent(ref: '#/components/schemas/AdminAlertEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Administrators only'),
            new OA\Response(response: 404, description: 'No such alert for this administrator'),
        ],
    )]
    public function markRead(Request $request, string $alert): JsonResponse
    {
        $this->authorize('use admin inbox');

        return $this->success(
            new AdminAlertResource($this->alertService->markRead($request->user(), $alert)),
            'Alert marked as read.',
        );
    }

    #[OA\Post(
        path: '/api/inbox/read-all',
        summary: 'Mark all of the administrator\'s alerts as read',
        tags: ['Admin Inbox'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Alerts marked read', content: new OA\JsonContent(ref: '#/components/schemas/ClientReadAllEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Administrators only'),
        ],
    )]
    public function readAll(Request $request): JsonResponse
    {
        $this->authorize('use admin inbox');

        return $this->success([
            'updated_count' => $this->alertService->readAll($request->user()),
        ], 'All alerts marked as read.');
    }
}
