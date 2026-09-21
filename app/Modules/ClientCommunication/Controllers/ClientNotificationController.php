<?php

namespace App\Modules\ClientCommunication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientCommunication\Requests\BackgroundNotificationIndexRequest;
use App\Modules\ClientCommunication\Requests\ClientNotificationIndexRequest;
use App\Modules\ClientCommunication\Resources\ClientNotificationResource;
use App\Modules\ClientCommunication\Services\BackgroundNotificationService;
use App\Modules\ClientCommunication\Services\ClientNotificationService;
use App\Shared\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Client Notifications', description: 'Customer notification inbox and read state')]
class ClientNotificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ClientNotificationService $notificationService,
        private readonly BackgroundNotificationService $backgroundService,
    ) {}

    #[OA\Post(
        path: '/api/client/v1/notifications/background-token',
        summary: 'Issue a read-only token for background notification checks',
        description: 'Called by the app after sign-in. The token can only call GET /notifications/background; it never refreshes and ends when the user signs out or changes their password. Up to five are kept per account (one per device).',
        tags: ['Client Notifications'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Background token issued', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: ['success' => true, 'message' => 'Background token issued.', 'data' => ['token' => '42|…', 'expires_at' => '2027-09-21T10:00:00+00:00'], 'errors' => null, 'meta' => []],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified mobile account with a full session token required'),
        ],
    )]
    public function backgroundToken(Request $request): JsonResponse
    {
        return $this->success($this->backgroundService->issueToken($request->user()), 'Background token issued.', status: 201);
    }

    #[OA\Get(
        path: '/api/client/v1/notifications/background',
        summary: 'Unread notifications for the background check',
        description: 'Authenticated with the background token only. Returns up to 10 unread notifications created at or after `after`, oldest first; without `after`, the last 24 hours. Muted categories are never stored, so everything returned may be shown.',
        tags: ['Client Notifications'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'after', in: 'query', required: false, description: 'created_at of the newest notification the device already showed', schema: new OA\Schema(type: 'string', format: 'date-time'))],
        responses: [
            new OA\Response(response: 200, description: 'Pending notifications (same shape as the feed)', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or token expired'),
            new OA\Response(response: 403, description: 'Not a background token, or the account is inactive'),
            new OA\Response(response: 422, description: 'Invalid `after`'),
        ],
    )]
    public function background(BackgroundNotificationIndexRequest $request): JsonResponse
    {
        $after = $request->validated('after');

        return $this->success(
            ClientNotificationResource::collection(
                $this->backgroundService->pending($request->user(), $after ? Carbon::parse($after) : null),
            ),
            'Pending notifications retrieved.',
        );
    }

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
            new OA\Response(response: 403, description: 'Active, verified customer or provider account required'),
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
        responses: [new OA\Response(response: 200, description: 'Unread count', content: new OA\JsonContent(ref: '#/components/schemas/ClientUnreadCountEnvelope')), new OA\Response(response: 403, description: 'Active, verified customer or provider account required')],
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
            new OA\Response(response: 403, description: 'Active, verified customer or provider account required'),
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
        responses: [new OA\Response(response: 200, description: 'Notifications marked read', content: new OA\JsonContent(ref: '#/components/schemas/ClientReadAllEnvelope')), new OA\Response(response: 403, description: 'Active, verified customer or provider account required')],
    )]
    public function readAll(Request $request): JsonResponse
    {
        return $this->success([
            'updated_count' => $this->notificationService->readAll($request->user()),
        ], 'Notifications marked as read.');
    }
}
