<?php

namespace App\Modules\ClientCommunication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientCommunication\Requests\ClientSupportTicketIndexRequest;
use App\Modules\ClientCommunication\Requests\StoreClientSupportTicketRequest;
use App\Modules\ClientCommunication\Resources\ClientSupportTicketResource;
use App\Modules\ClientCommunication\Services\ClientSupportTicketService;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Requests\StoreSupportTicketResponseRequest;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Client Support', description: 'Customer-owned support tickets and replies')]
class ClientSupportTicketController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ClientSupportTicketService $supportService) {}

    #[OA\Get(
        path: '/api/client/v1/support/tickets',
        summary: 'List the authenticated customer support tickets',
        tags: ['Client Support'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'in_progress', 'resolved'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated customer tickets', content: new OA\JsonContent(ref: '#/components/schemas/ClientSupportTicketListEnvelope')), new OA\Response(response: 403, description: 'Active, verified client access required'), new OA\Response(response: 422, description: 'Invalid filter')],
    )]
    public function index(ClientSupportTicketIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->supportService->index($request->user(), $request->validated()),
            ClientSupportTicketResource::class,
            'Support tickets retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/support/tickets',
        summary: 'Create a support ticket for the authenticated customer',
        tags: ['Client Support'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['subject', 'description'], properties: [
            new OA\Property(property: 'subject', type: 'string', maxLength: 160),
            new OA\Property(property: 'description', type: 'string', maxLength: 10000),
            new OA\Property(property: 'category', type: 'string', maxLength: 40, nullable: true),
        ])),
        responses: [new OA\Response(response: 201, description: 'Support ticket created', content: new OA\JsonContent(ref: '#/components/schemas/ClientSupportTicketEnvelope')), new OA\Response(response: 403, description: 'Active, verified client access required'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(StoreClientSupportTicketRequest $request): JsonResponse
    {
        return $this->success(
            new ClientSupportTicketResource($this->supportService->create($request->user(), $request->validated())),
            'Support ticket created.',
            status: 201,
        );
    }

    #[OA\Get(
        path: '/api/client/v1/support/tickets/{ticket}',
        summary: 'Get an owned customer support ticket',
        tags: ['Client Support'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Support ticket details', content: new OA\JsonContent(ref: '#/components/schemas/ClientSupportTicketEnvelope')), new OA\Response(response: 403, description: 'Active, verified client access required'), new OA\Response(response: 404, description: 'Ticket not found')],
    )]
    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        return $this->success(new ClientSupportTicketResource($this->supportService->show($request->user(), $ticket)), 'Support ticket retrieved.');
    }

    #[OA\Post(
        path: '/api/client/v1/support/tickets/{ticket}/replies',
        summary: 'Reply to an owned customer support ticket',
        tags: ['Client Support'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['body'], properties: [new OA\Property(property: 'body', type: 'string', maxLength: 5000)])),
        responses: [new OA\Response(response: 200, description: 'Reply added', content: new OA\JsonContent(ref: '#/components/schemas/ClientSupportTicketEnvelope')), new OA\Response(response: 403, description: 'Active, verified client access required'), new OA\Response(response: 404, description: 'Ticket not found'), new OA\Response(response: 422, description: 'Validation error or resolved ticket')],
    )]
    public function reply(StoreSupportTicketResponseRequest $request, SupportTicket $ticket): JsonResponse
    {
        return $this->success(
            new ClientSupportTicketResource($this->supportService->reply($request->user(), $ticket, $request->validated('body'))),
            'Support ticket reply added.',
        );
    }
}
