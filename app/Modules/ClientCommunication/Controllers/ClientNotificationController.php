<?php

namespace App\Modules\ClientCommunication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientCommunication\Requests\ClientNotificationIndexRequest;
use App\Modules\ClientCommunication\Resources\ClientNotificationResource;
use App\Modules\ClientCommunication\Services\ClientNotificationService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Client Notifications', description: 'Customer notification inbox and read state')]
class ClientNotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ClientNotificationService $notificationService) {}

    #[OA\Get(
        path: '/api/client/v1/notifications',
        summary: 'List the authenticated customer notification inbox',
        tags: ['Client Notifications'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated notification inbox', content: new OA\JsonContent(ref: '#/components/schemas/ClientNotificationListEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified client access required'),
            new OA\Response(response: 422, description: 'Invalid pagination'),
        ],
    )]
    public function index(ClientNotificationIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->notificationService->index($request->user(), $request->validated()),
            ClientNotificationResource::class,
            'Notifications retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/notifications/unread-count',
        summary: 'Count unread customer notifications',
        tags: ['Client Notifications'],
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Unread count', content: new OA\JsonContent(ref: '#/components/schemas/ClientUnreadCountEnvelope')), new OA\Response(response: 403, description: 'Active, verified client access required')],
    )]
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'unread_count' => $this->notificationService->unreadCount($request->user()),
        ], 'Unread notification count retrieved.');
    }

    #[OA\Patch(
        path: '/api/client/v1/notifications/{notification}/read',
        summary: 'Mark one owned notification as read',
        tags: ['Client Notifications'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Notification marked read', content: new OA\JsonContent(ref: '#/components/schemas/ClientNotificationEnvelope')),
            new OA\Response(response: 403, description: 'Active, verified client access required'),
            new OA\Response(response: 404, description: 'Notification not found'),
        ],
    )]
    public function markRead(Request $request, string $notification): JsonResponse
    {
        return $this->success(
            new ClientNotificationResource($this->notificationService->markRead($request->user(), $notification)),
            'Notification marked as read.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/notifications/read-all',
        summary: 'Mark all owned notifications as read',
        tags: ['Client Notifications'],
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Notifications marked read', content: new OA\JsonContent(ref: '#/components/schemas/ClientReadAllEnvelope')), new OA\Response(response: 403, description: 'Active, verified client access required')],
    )]
    public function readAll(Request $request): JsonResponse
    {
        return $this->success([
            'updated_count' => $this->notificationService->readAll($request->user()),
        ], 'Notifications marked as read.');
    }
}
