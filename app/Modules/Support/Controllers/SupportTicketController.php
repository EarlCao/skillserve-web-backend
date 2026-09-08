<?php

namespace App\Modules\Support\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Requests\AssigneeIndexRequest;
use App\Modules\Support\Requests\AssignSupportTicketRequest;
use App\Modules\Support\Requests\ResolveSupportTicketRequest;
use App\Modules\Support\Requests\StoreSupportTicketResponseRequest;
use App\Modules\Support\Requests\SupportTicketIndexRequest;
use App\Modules\Support\Resources\SupportTicketResource;
use App\Modules\Support\Services\SupportTicketService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Support', description: 'Manage support tickets, assignments, responses, and resolutions')]
class SupportTicketController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SupportTicketService $supportService) {}

    #[OA\Get(
        path: '/api/support/tickets',
        summary: 'List support tickets',
        tags: ['Support'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'in_progress', 'resolved'])),
            new OA\Parameter(name: 'priority', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['low', 'normal', 'high', 'urgent'])),
            new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'assigned_to', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['created_at', 'updated_at', 'priority', 'status'])),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated support tickets', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ],
    )]
    public function index(SupportTicketIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', SupportTicket::class);

        return $this->paginated(
            $this->supportService->index($request->validated()),
            SupportTicketResource::class,
            'Support tickets retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/support/tickets/assignees',
        summary: 'List active administrator assignees',
        tags: ['Support'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Active assignees', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'))],
    )]
    public function assignees(AssigneeIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', SupportTicket::class);

        return $this->success($this->supportService->assignees($request->validated('search')), 'Support assignees retrieved.');
    }

    #[OA\Get(
        path: '/api/support/tickets/{ticket}',
        summary: 'Get support ticket details',
        tags: ['Support'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Support ticket details', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 404, description: 'Support ticket not found'),
        ],
    )]
    public function show(SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return $this->success(new SupportTicketResource($this->supportService->show($ticket)), 'Support ticket retrieved.');
    }

    #[OA\Patch(
        path: '/api/support/tickets/{ticket}/assign',
        summary: 'Assign or unassign a support ticket',
        tags: ['Support'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'assigned_to', type: 'integer', nullable: true)], example: ['assigned_to' => 2])),
        responses: [new OA\Response(response: 200, description: 'Ticket assignment updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Invalid assignee')],
    )]
    public function assign(AssignSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('assign', $ticket);

        return $this->success(new SupportTicketResource($this->supportService->assign($ticket, $request->validated('assigned_to'), $request->user())), 'Support ticket assignment updated.');
    }

    #[OA\Post(
        path: '/api/support/tickets/{ticket}/responses',
        summary: 'Add a support ticket response',
        tags: ['Support'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['body'], properties: [new OA\Property(property: 'body', type: 'string', maxLength: 5000)], example: ['body' => 'We have reviewed your request.'])),
        responses: [new OA\Response(response: 200, description: 'Response added', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Resolved ticket or validation error')],
    )]
    public function respond(StoreSupportTicketResponseRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('respond', $ticket);

        return $this->success(new SupportTicketResource($this->supportService->addResponse($ticket, $request->user(), $request->validated('body'))), 'Support ticket response added.');
    }

    #[OA\Patch(
        path: '/api/support/tickets/{ticket}/resolve',
        summary: 'Resolve a support ticket',
        tags: ['Support'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['resolution_note'], properties: [new OA\Property(property: 'resolution_note', type: 'string', maxLength: 2000)], example: ['resolution_note' => 'The issue was resolved.'])),
        responses: [new OA\Response(response: 200, description: 'Ticket resolved', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Already resolved or validation error')],
    )]
    public function resolve(ResolveSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('resolve', $ticket);

        return $this->success(new SupportTicketResource($this->supportService->resolve($ticket, $request->user(), $request->validated('resolution_note'))), 'Support ticket resolved.');
    }
}
