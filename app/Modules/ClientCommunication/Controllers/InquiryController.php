<?php

namespace App\Modules\ClientCommunication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientCommunication\Models\Inquiry;
use App\Modules\ClientCommunication\Requests\StoreBookingMessageRequest;
use App\Modules\ClientCommunication\Requests\StoreInquiryRequest;
use App\Modules\ClientCommunication\Resources\BookingMessageResource;
use App\Modules\ClientCommunication\Resources\ConversationResource;
use App\Modules\ClientCommunication\Services\ConversationService;
use App\Modules\ClientCommunication\Services\InquiryService;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Tag(name: 'Inquiries', description: 'Chat before booking: a customer\'s conversation with a provider, one per provider. The customer starts it; both sides write in it like a booking chat.')]
class InquiryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly InquiryService $inquiryService,
        private readonly ConversationService $conversationService,
    ) {}

    #[OA\Post(
        path: '/api/client/v1/inquiries',
        summary: 'Start (or reopen) a conversation with a provider before booking',
        description: 'Customers only. Returns the one conversation the customer has with this provider, creating it the first time. Only providers the marketplace shows can be messaged.',
        tags: ['Inquiries'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['provider_id'], properties: [new OA\Property(property: 'provider_id', type: 'integer', description: 'The provider\'s public profile id')])),
        responses: [
            new OA\Response(response: 200, description: 'The conversation (kind `inquiry`)', content: new OA\JsonContent(ref: '#/components/schemas/ConversationEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified customer account required'),
            new OA\Response(response: 404, description: 'Provider not found or not listed'),
            new OA\Response(response: 422, description: 'Validation error, or the provider is the caller'),
            new OA\Response(response: 429, description: 'Too many conversations started'),
        ],
    )]
    public function store(StoreInquiryRequest $request): JsonResponse
    {
        return $this->success(
            new ConversationResource($this->inquiryService->start($request->user(), (int) $request->validated('provider_id'))),
            'Conversation ready.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/inquiries/{inquiry}/messages',
        summary: 'List the messages of a conversation before booking',
        description: 'Oldest first. Opening it marks the messages addressed to the caller as read.',
        tags: ['Inquiries'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Paginated messages', content: new OA\JsonContent(ref: '#/components/schemas/BookingMessageListEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Only the customer and the provider'), new OA\Response(response: 404, description: 'Conversation not found')],
    )]
    public function index(Request $request, Inquiry $inquiry): JsonResponse
    {
        return $this->paginated(
            $this->inquiryService->messages($request->user(), $inquiry),
            BookingMessageResource::class,
            'Messages retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/inquiries/{inquiry}/messages',
        summary: 'Send a message in a conversation before booking',
        description: 'Text, a photo, or both — exactly as in a booking chat: JSON for text, or multipart/form-data with `image` (JPG, PNG or WebP, at most 5 MB) and an optional `content`. An `Idempotency-Key` makes a retried send return the stored message.',
        tags: ['Inquiries'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 100))],
        requestBody: new OA\RequestBody(required: true, content: [
            new OA\JsonContent(required: ['content'], properties: [new OA\Property(property: 'content', type: 'string', maxLength: 5000)]),
            new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(properties: [
                new OA\Property(property: 'content', type: 'string', maxLength: 5000, nullable: true),
                new OA\Property(property: 'image', type: 'string', format: 'binary'),
            ])),
        ]),
        responses: [new OA\Response(response: 201, description: 'Message sent', content: new OA\JsonContent(ref: '#/components/schemas/BookingMessageEnvelope')), new OA\Response(response: 200, description: 'Existing message returned for a repeated idempotency key', content: new OA\JsonContent(ref: '#/components/schemas/BookingMessageEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Only the customer and the provider'), new OA\Response(response: 404, description: 'Conversation not found'), new OA\Response(response: 409, description: 'Idempotency conflict'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeMessage(StoreBookingMessageRequest $request, Inquiry $inquiry): JsonResponse
    {
        $message = $this->inquiryService->send(
            $request->user(),
            $inquiry,
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
        path: '/api/client/v1/inquiries/{inquiry}/messages/read',
        summary: 'Mark the messages received in a conversation before booking as read',
        tags: ['Inquiries'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Marked read', content: new OA\JsonContent(ref: '#/components/schemas/MessagesMarkedReadEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Only the customer and the provider'), new OA\Response(response: 404, description: 'Conversation not found')],
    )]
    public function markRead(Request $request, Inquiry $inquiry): JsonResponse
    {
        $marked = $this->inquiryService->markRead($request->user(), $inquiry);

        return $this->success([
            'marked_read' => $marked,
            'unread_count' => $this->conversationService->unreadCount($request->user()),
        ], 'Messages marked as read.');
    }

    #[OA\Get(
        path: '/api/client/v1/inquiries/{inquiry}/messages/{message}/image',
        summary: 'The photo of a message in a conversation before booking',
        description: 'Streamed from private storage to the two parties only.',
        tags: ['Inquiries'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'The image file'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Only the customer and the provider'), new OA\Response(response: 404, description: 'No such photo in this conversation')],
    )]
    public function image(Request $request, Inquiry $inquiry, Message $message): StreamedResponse
    {
        return $this->inquiryService->image($request->user(), $inquiry, $message);
    }
}
