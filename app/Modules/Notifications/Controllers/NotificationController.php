<?php

namespace App\Modules\Notifications\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Requests\IndexNotificationRequest;
use App\Modules\Notifications\Requests\RecipientsRequest;
use App\Modules\Notifications\Requests\StoreAnnouncementRequest;
use App\Modules\Notifications\Resources\AnnouncementResource;
use App\Modules\Notifications\Services\AnnouncementService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Notifications', description: 'View notification history and send targeted announcements')]
class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AnnouncementService $announcementService) {}

    #[OA\Get(path: '/api/notifications', summary: 'List notification history', tags: ['Notifications'], security: [['bearerAuth' => []]], parameters: [
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'scheduled', 'sent', 'failed'])),
        new OA\Parameter(name: 'target', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['all', 'customers', 'providers', 'selected'])),
        new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['created_at', 'scheduled_at', 'status'], default: 'created_at')),
        new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
    ], responses: [new OA\Response(response: 200, description: 'Notification history', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function index(IndexNotificationRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Announcement::class);

        return $this->paginated(
            $this->announcementService->index($request->only(['search', 'status', 'target', 'sort', 'direction', 'per_page'])),
            AnnouncementResource::class,
            'Notifications retrieved.',
        );
    }

    #[OA\Get(path: '/api/notifications/recipients', summary: 'List announcement recipients', tags: ['Notifications'], security: [['bearerAuth' => []]], parameters: [
        new OA\Parameter(name: 'target', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['all', 'customers', 'providers', 'selected'])),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ], responses: [new OA\Response(response: 200, description: 'Recipients', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function recipients(RecipientsRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('target notifications'), 403);

        return $this->success($this->announcementService->recipients(
            $request->validated('target'),
            $request->validated('search'),
        ));
    }

    #[OA\Post(path: '/api/notifications/announcements', summary: 'Send or schedule an announcement', tags: ['Notifications'], security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['title', 'message', 'target'],
        properties: [
            new OA\Property(property: 'title', type: 'string', maxLength: 160, example: 'Platform maintenance'),
            new OA\Property(property: 'message', type: 'string', maxLength: 10000, example: 'The platform will be unavailable tonight.'),
            new OA\Property(property: 'target', type: 'string', enum: ['all', 'customers', 'providers', 'selected'], example: 'customers'),
            new OA\Property(property: 'recipient_ids', type: 'array', items: new OA\Items(type: 'integer'), nullable: true),
            new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', nullable: true),
        ],
    )), responses: [new OA\Response(response: 201, description: 'Announcement created', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Validation error'), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $this->authorize('create', Announcement::class);

        if ($request->filled('scheduled_at')) {
            abort_unless($request->user()->can('schedule announcements'), 403);
        }

        if ($request->input('target') !== 'all') {
            abort_unless($request->user()->can('target notifications'), 403);
        }

        return $this->success(
            new AnnouncementResource($this->announcementService->store($request->validated(), $request->user())),
            'Announcement created.',
            [],
            201,
        );
    }
}
