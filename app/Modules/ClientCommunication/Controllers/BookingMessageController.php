<?php

namespace App\Modules\ClientCommunication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Requests\StoreBookingMessageRequest;
use App\Modules\ClientCommunication\Resources\BookingMessageResource;
use App\Modules\ClientCommunication\Services\BookingMessageService;
use App\Modules\ClientCommunication\Services\ConversationService;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Tag(name: 'Booking Messages', description: 'Messages exchanged by the client and provider on a booking')]
class BookingMessageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingMessageService $messageService,
        private readonly ConversationService $conversationService,
    ) {}

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

    #[OA\Get(
        path: '/api/client/v1/bookings/{booking}/messages/{message}/image',
        summary: 'The photo of a chat message',
        description: 'Streamed from private storage to the booking\'s two parties only.',
        tags: ['Booking Messages'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The image file'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Booking participant access required'),
            new OA\Response(response: 404, description: 'No such photo in this booking\'s chat'),
        ],
    )]
    public function image(Request $request, Booking $booking, Message $message): StreamedResponse
    {
        return $this->messageService->image($request->user(), $booking, $message);
    }

    #[OA\Post(
        path: '/api/client/v1/bookings/{booking}/messages',
        summary: 'Send a message to the other booking participant',
        tags: ['Booking Messages'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 100))],
        description: 'Text, a photo, or both. Send JSON for text only, or multipart/form-data with `image` (JPG, PNG or WebP, at most 5 MB) and an optional `content` caption. The photo is private: the message carries an `image_url` to GET .../messages/{message}/image, readable by the two parties only.',
        requestBody: new OA\RequestBody(required: true, content: [
            new OA\JsonContent(required: ['content'], properties: [new OA\Property(property: 'content', type: 'string', maxLength: 5000)]),
            new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(properties: [
                new OA\Property(property: 'content', type: 'string', maxLength: 5000, nullable: true, description: 'Optional caption'),
                new OA\Property(property: 'image', type: 'string', format: 'binary'),
            ])),
        ]),
        responses: [new OA\Response(response: 201, description: 'Message sent', content: new OA\JsonContent(ref: '#/components/schemas/BookingMessageEnvelope')), new OA\Response(response: 200, description: 'Existing message returned for a repeated idempotency key', content: new OA\JsonContent(ref: '#/components/schemas/BookingMessageEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Booking participant access required'), new OA\Response(response: 404, description: 'Booking not found'), new OA\Response(response: 409, description: 'Idempotency conflict'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(StoreBookingMessageRequest $request, Booking $booking): JsonResponse
    {
        $message = $this->messageService->create(
            $request->user(),
            $booking,
            $request->validated('content'),
            $request->idempotencyKey(),
            $request->file('image'),
        );

        return $this->success(
            new BookingMessageResource($message),
            $message->wasRecentlyCreated ? 'Message sent.' : 'Message already sent.',
            status: $message->wasRecentlyCreated ? 201 : 200,
        );
    }

    #[OA\Post(
        path: '/api/client/v1/bookings/{booking}/messages/read',
        summary: 'Mark the messages received on a booking as read',
        description: 'For an open conversation that already shows a pushed message: clears its unread state without re-reading the thread, and returns the account\'s remaining unread total for the Messages badge.',
        tags: ['Booking Messages'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Marked read', content: new OA\JsonContent(ref: '#/components/schemas/MessagesMarkedReadEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Booking participant access required'),
            new OA\Response(response: 404, description: 'Booking not found'),
        ],
    )]
    public function markRead(Request $request, Booking $booking): JsonResponse
    {
        $marked = $this->messageService->markRead($request->user(), $booking);

        return $this->success([
            'marked_read' => $marked,
            'unread_count' => $this->conversationService->unreadCount($request->user()),
        ], 'Messages marked as read.');
    }
}
