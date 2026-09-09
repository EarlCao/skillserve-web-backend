<?php

namespace App\Modules\Bookings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Requests\AddDisputeNoteRequest;
use App\Modules\Bookings\Requests\CloseDisputeRequest;
use App\Modules\Bookings\Requests\DisputeIndexRequest;
use App\Modules\Bookings\Requests\HistoryIndexRequest;
use App\Modules\Bookings\Requests\ResolveDisputeRequest;
use App\Modules\Bookings\Resources\BookingResource;
use App\Modules\Bookings\Services\DisputeService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Disputes', description: 'Review and manage booking disputes')]
class DisputeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DisputeService $disputeService) {}

    #[OA\Get(
        path: '/api/disputes',
        summary: 'List booking disputes',
        tags: ['Disputes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'investigated', 'resolved', 'rejected', 'closed'])),
            new OA\Parameter(name: 'provider_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'service_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['booking_number', 'created_at', 'disputed_at', 'dispute_status'])),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated disputes', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ],
    )]
    public function index(DisputeIndexRequest $request): JsonResponse
    {
        $this->authorize('viewDisputes', Booking::class);

        return $this->paginated(
            $this->disputeService->index($request->validated()),
            BookingResource::class,
            'Disputes retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/disputes/{booking}',
        summary: 'View dispute details',
        tags: ['Disputes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Dispute details', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Booking has no dispute'),
        ],
    )]
    public function show(Booking $booking): JsonResponse
    {
        $this->authorize('viewDisputes', Booking::class);

        return $this->success(new BookingResource($this->disputeService->show($booking)), 'Dispute retrieved.');
    }

    #[OA\Get(
        path: '/api/disputes/{booking}/history',
        summary: 'View paginated dispute history',
        tags: ['Disputes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated dispute history', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'))],
    )]
    public function history(HistoryIndexRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('viewDisputes', Booking::class);

        return $this->paginated($this->disputeService->history($booking, $request->validated()), null, 'Dispute history retrieved.');
    }

    #[OA\Patch(path: '/api/disputes/{booking}/investigate', summary: 'Investigate a dispute', tags: ['Disputes'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Dispute under investigation', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope'))])]
    public function investigate(Booking $booking, Request $request): JsonResponse
    {
        $this->authorize('manageDispute', $booking);

        return $this->success(new BookingResource($this->disputeService->investigate($booking, $request->user())), 'Dispute under investigation.');
    }

    #[OA\Patch(path: '/api/disputes/{booking}/notes', summary: 'Add dispute notes', tags: ['Disputes'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['note'], properties: [new OA\Property(property: 'note', type: 'string', maxLength: 2000)])), responses: [new OA\Response(response: 200, description: 'Note added', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Validation or state error')])]
    public function addNote(AddDisputeNoteRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('manageDispute', $booking);

        return $this->success(new BookingResource($this->disputeService->addNote($booking, $request->user(), $request->validated('note'))), 'Dispute note added.');
    }

    #[OA\Patch(path: '/api/disputes/{booking}/resolve', summary: 'Resolve a dispute', tags: ['Disputes'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['resolution'], properties: [new OA\Property(property: 'resolution', type: 'string', maxLength: 2000)])), responses: [new OA\Response(response: 200, description: 'Dispute resolved', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Validation or state error')])]
    public function resolve(ResolveDisputeRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('manageDispute', $booking);

        return $this->success(new BookingResource($this->disputeService->resolve($booking, $request->user(), $request->validated('resolution'))), 'Dispute resolved.');
    }

    #[OA\Patch(path: '/api/disputes/{booking}/reject', summary: 'Reject a dispute', tags: ['Disputes'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true)])), responses: [new OA\Response(response: 200, description: 'Dispute rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Validation or state error')])]
    public function reject(CloseDisputeRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('manageDispute', $booking);

        return $this->success(new BookingResource($this->disputeService->reject($booking, $request->user(), $request->validated('note'))), 'Dispute rejected.');
    }

    #[OA\Patch(path: '/api/disputes/{booking}/close', summary: 'Close a resolved dispute', tags: ['Disputes'], security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true)])), responses: [new OA\Response(response: 200, description: 'Dispute closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Validation or state error')])]
    public function close(CloseDisputeRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('manageDispute', $booking);

        return $this->success(new BookingResource($this->disputeService->close($booking, $request->user(), $request->validated('note'))), 'Dispute closed.');
    }
}
