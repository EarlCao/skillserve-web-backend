<?php

namespace App\Modules\ClientCommunication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Requests\StoreBookingMessageRequest;
use App\Modules\ClientCommunication\Resources\BookingMessageResource;
use App\Modules\ClientCommunication\Services\BookingMessageService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Booking Messages', description: 'Messages exchanged by the client and provider on a booking')]
class BookingMessageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingMessageService $messageService) {}

    #[OA\Get(
        path: '/api/client/v1/bookings/{booking}/messages',
        summary: 'List messages for a booking participant',
        tags: ['Booking Messages'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Paginated booking messages', content: new OA\JsonContent(ref: '#/components/schemas/BookingMessageListEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Booking participant access required'), new OA\Response(response: 404, description: 'Booking not found')],
    )]
    public function index(Request $request, Booking $booking): JsonResponse
    {
        return $this->paginated(
            $this->messageService->index($request->user(), $booking),
            BookingMessageResource::class,
            'Booking messages retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/bookings/{booking}/messages',
        summary: 'Send a message to the other booking participant',
        tags: ['Booking Messages'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 100))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['content'], properties: [new OA\Property(property: 'content', type: 'string', maxLength: 5000)])),
        responses: [new OA\Response(response: 201, description: 'Message sent', content: new OA\JsonContent(ref: '#/components/schemas/BookingMessageEnvelope')), new OA\Response(response: 200, description: 'Existing message returned for a repeated idempotency key', content: new OA\JsonContent(ref: '#/components/schemas/BookingMessageEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Booking participant access required'), new OA\Response(response: 404, description: 'Booking not found'), new OA\Response(response: 409, description: 'Idempotency conflict'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(StoreBookingMessageRequest $request, Booking $booking): JsonResponse
    {
        $message = $this->messageService->create(
            $request->user(),
            $booking,
            $request->validated('content'),
            $request->idempotencyKey(),
        );

        return $this->success(
            new BookingMessageResource($message),
            $message->wasRecentlyCreated ? 'Message sent.' : 'Message already sent.',
            status: $message->wasRecentlyCreated ? 201 : 200,
        );
    }
}
