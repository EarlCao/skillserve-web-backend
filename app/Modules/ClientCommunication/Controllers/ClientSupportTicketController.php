<?php

namespace App\Modules\ClientCommunication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientCommunication\Requests\ClientSupportTicketIndexRequest;
use App\Modules\ClientCommunication\Requests\StoreClientSupportTicketReplyRequest;
use App\Modules\ClientCommunication\Requests\StoreClientSupportTicketRequest;
use App\Modules\ClientCommunication\Resources\ClientSupportTicketResource;
use App\Modules\ClientCommunication\Services\ClientSupportTicketService;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketAttachment;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Tag(name: 'Client Support', description: 'Customer-owned support tickets and replies')]
class ClientSupportTicketController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ClientSupportTicketService $supportService) {}

    #[OA\Get(
        path: '/api/client/v1/support/tickets',
        summary: 'List the signed-in account\'s support tickets',
        tags: ['Client Support'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'in_progress', 'resolved'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated tickets raised by the account', content: new OA\JsonContent(ref: '#/components/schemas/ClientSupportTicketListEnvelope')), new OA\Response(response: 403, description: 'Active, verified customer or provider account required'), new OA\Response(response: 422, description: 'Invalid filter')],
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
        summary: 'Raise a support ticket as a customer or provider',
        description: 'Send JSON, or multipart/form-data to attach up to 5 photos (screenshots, proof) in `images[]` — JPG, PNG or WebP, 5 MB each. Support staff see them with the ticket.',
        tags: ['Client Support'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: [
            new OA\JsonContent(required: ['subject', 'description'], properties: [
                new OA\Property(property: 'subject', type: 'string', maxLength: 160),
                new OA\Property(property: 'description', type: 'string', maxLength: 10000),
                new OA\Property(property: 'category', type: 'string', maxLength: 40, nullable: true),
            ]),
            new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['subject', 'description'], properties: [
                new OA\Property(property: 'subject', type: 'string', maxLength: 160),
                new OA\Property(property: 'description', type: 'string', maxLength: 10000),
                new OA\Property(property: 'category', type: 'string', maxLength: 40),
                new OA\Property(property: 'images[]', type: 'array', maxItems: 5, items: new OA\Items(type: 'string', format: 'binary')),
            ])),
        ]),
        responses: [new OA\Response(response: 201, description: 'Support ticket created', content: new OA\JsonContent(ref: '#/components/schemas/ClientSupportTicketEnvelope')), new OA\Response(response: 403, description: 'Active, verified customer or provider account required'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(StoreClientSupportTicketRequest $request): JsonResponse
    {
        return $this->success(
            new ClientSupportTicketResource($this->supportService->create($request->user(), $request->validated(), $request->file('images', []))),
            'Support ticket created.',
            status: 201,
        );
    }

    #[OA\Get(
        path: '/api/client/v1/support/tickets/{ticket}',
        summary: 'Get a support ticket the account raised',
        tags: ['Client Support'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Support ticket details', content: new OA\JsonContent(ref: '#/components/schemas/ClientSupportTicketEnvelope')), new OA\Response(response: 403, description: 'Active, verified customer or provider account required'), new OA\Response(response: 404, description: 'Ticket not found')],
    )]
    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        return $this->success(new ClientSupportTicketResource($this->supportService->show($request->user(), $ticket)), 'Support ticket retrieved.');
    }

    #[OA\Post(
        path: '/api/client/v1/support/tickets/{ticket}/replies',
        summary: 'Reply to a support ticket the account raised',
        description: 'Text, photos, or both: `body` is required unless `images[]` (multipart, up to 5 JPG/PNG/WebP photos of 5 MB) is sent.',
        tags: ['Client Support'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: [
            new OA\JsonContent(required: ['body'], properties: [new OA\Property(property: 'body', type: 'string', maxLength: 5000)]),
            new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(properties: [
                new OA\Property(property: 'body', type: 'string', maxLength: 5000),
                new OA\Property(property: 'images[]', type: 'array', maxItems: 5, items: new OA\Items(type: 'string', format: 'binary')),
            ])),
        ]),
        responses: [new OA\Response(response: 200, description: 'Reply added', content: new OA\JsonContent(ref: '#/components/schemas/ClientSupportTicketEnvelope')), new OA\Response(response: 403, description: 'Active, verified customer or provider account required'), new OA\Response(response: 404, description: 'Ticket not found'), new OA\Response(response: 422, description: 'Validation error or resolved ticket')],
    )]
    public function reply(StoreClientSupportTicketReplyRequest $request, SupportTicket $ticket): JsonResponse
    {
        return $this->success(
            new ClientSupportTicketResource($this->supportService->reply(
                $request->user(),
                $ticket,
                $request->validated('body'),
                $request->file('images', []),
            )),
            'Support ticket reply added.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/support/tickets/{ticket}/attachments/{attachment}',
        summary: 'A photo on a support ticket the account raised',
        description: 'Streamed from private storage to the requester only. The URLs come from the ticket\'s and each message\'s `attachments`.',
        tags: ['Client Support'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'attachment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The photo', content: new OA\MediaType(mediaType: 'image/*')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not the caller\'s ticket, or no such photo'),
        ],
    )]
    public function attachment(Request $request, SupportTicket $ticket, SupportTicketAttachment $attachment): StreamedResponse
    {
        return $this->supportService->attachment($request->user(), $ticket, $attachment);
    }
}
