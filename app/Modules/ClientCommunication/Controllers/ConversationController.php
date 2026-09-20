<?php

namespace App\Modules\ClientCommunication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientCommunication\Requests\ConversationIndexRequest;
use App\Modules\ClientCommunication\Resources\ConversationResource;
use App\Modules\ClientCommunication\Services\ConversationService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Conversations', description: 'The signed-in account\'s booking message threads')]
class ConversationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConversationService $conversationService) {}

    #[OA\Get(
        path: '/api/client/v1/conversations',
        summary: 'List the signed-in account\'s booking conversations',
        description: 'Bookings the account can message on that already carry at least one message, newest activity first. Reading this list does not mark anything as read.',
        tags: ['Conversations'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20))],
        responses: [
            new OA\Response(response: 200, description: 'Conversations', content: new OA\JsonContent(ref: '#/components/schemas/ConversationListEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified customer or provider account required'),
            new OA\Response(response: 422, description: 'Invalid pagination'),
        ],
    )]
    public function index(ConversationIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->conversationService->index($request->user(), $request->validated()),
            ConversationResource::class,
            'Conversations retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/conversations/unread-count',
        summary: 'Count every unread message addressed to the signed-in account',
        tags: ['Conversations'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Unread message count', content: new OA\JsonContent(ref: '#/components/schemas/UnreadMessageCountEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified customer or provider account required'),
        ],
    )]
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'unread_count' => $this->conversationService->unreadCount($request->user()),
        ], 'Unread message count retrieved.');
    }
}
