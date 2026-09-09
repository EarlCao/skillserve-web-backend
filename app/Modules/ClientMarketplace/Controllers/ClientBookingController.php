<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientMarketplace\Policies\ClientBookingPolicy;
use App\Modules\ClientMarketplace\Requests\CancelClientBookingRequest;
use App\Modules\ClientMarketplace\Requests\ClientBookingIndexRequest;
use App\Modules\ClientMarketplace\Requests\StoreClientBookingRequest;
use App\Modules\ClientMarketplace\Resources\ClientBookingResource;
use App\Modules\ClientMarketplace\Services\ClientBookingService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Client Bookings', description: 'Customer-owned bookings')]
class ClientBookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ClientBookingService $bookingService,
        private readonly ClientBookingPolicy $bookingPolicy,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/bookings',
        summary: 'List the authenticated customer bookings',
        tags: ['Client Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'confirmed', 'active', 'completed', 'cancelled', 'disputed'])),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'scheduled_date', 'status', 'total_price'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Customer bookings', content: new OA\JsonContent(ref: '#/components/schemas/ClientBookingListEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Active, verified client access required'), new OA\Response(response: 422, description: 'Invalid filter')],
    )]
    public function index(ClientBookingIndexRequest $request): JsonResponse
    {
        $this->ensure($this->bookingPolicy->viewAny($request->user()));

        return $this->paginated(
            $this->bookingService->index($request->user(), $request->validated()),
            ClientBookingResource::class,
            'Bookings retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/bookings',
        summary: 'Create a booking from a published service',
        tags: ['Client Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 100))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['service_id', 'scheduled_date'],
            properties: [
                new OA\Property(property: 'service_id', type: 'integer'),
                new OA\Property(property: 'scheduled_date', type: 'string', format: 'date-time', description: 'Booking start. The service duration is used when scheduled_end_date is omitted.'),
                new OA\Property(property: 'scheduled_end_date', type: 'string', format: 'date-time', nullable: true, description: 'Optional explicit end; must be after scheduled_date.'),
                new OA\Property(property: 'client_notes', type: 'string', nullable: true, maxLength: 2000),
                new OA\Property(property: 'payment_method', type: 'string', nullable: true, enum: StoreClientBookingRequest::PAYMENT_METHODS, description: 'Selection only; payment remains unpaid because no payment provider is called.'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Booking created', content: new OA\JsonContent(ref: '#/components/schemas/ClientBookingEnvelope')),
            new OA\Response(response: 200, description: 'Existing booking returned for a repeated idempotency key', content: new OA\JsonContent(ref: '#/components/schemas/ClientBookingEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified client access required'),
            new OA\Response(response: 409, description: 'Idempotency or provider schedule conflict'),
            new OA\Response(response: 422, description: 'Unavailable or unbookable service'),
        ],
    )]
    public function store(StoreClientBookingRequest $request): JsonResponse
    {
        $this->ensure($this->bookingPolicy->create($request->user()));
        $booking = $this->bookingService->create(
            $request->user(),
            $request->validated(),
            $request->idempotencyKey(),
        );

        return $this->success(
            new ClientBookingResource($booking),
            $booking->wasRecentlyCreated ? 'Booking created.' : 'Booking already created.',
            status: $booking->wasRecentlyCreated ? 201 : 200,
        );
    }

    #[OA\Get(
        path: '/api/client/v1/bookings/{booking}',
        summary: 'Get an owned booking',
        tags: ['Client Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Booking details', content: new OA\JsonContent(ref: '#/components/schemas/ClientBookingEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Not owned by the customer or email is unverified'), new OA\Response(response: 404, description: 'Booking not found')],
    )]
    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->bookingPolicy->view($request->user(), $booking));

        return $this->success(new ClientBookingResource($this->bookingService->show($request->user(), $booking)), 'Booking retrieved.');
    }

    #[OA\Patch(
        path: '/api/client/v1/bookings/{booking}/cancel',
        summary: 'Cancel an owned pending or confirmed booking without processing a refund',
        tags: ['Client Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 1000)])),
        responses: [new OA\Response(response: 200, description: 'Booking cancelled. Unpaid remains unpaid; paid state is unchanged and no external refund is processed.', content: new OA\JsonContent(ref: '#/components/schemas/ClientBookingEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Not owned by the customer'), new OA\Response(response: 404, description: 'Booking not found'), new OA\Response(response: 422, description: 'Booking cannot be cancelled')],
    )]
    public function cancel(CancelClientBookingRequest $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->bookingPolicy->cancel($request->user(), $booking));

        return $this->success(
            new ClientBookingResource($this->bookingService->cancel($request->user(), $booking, $request->validated('reason'))),
            'Booking cancelled.',
        );
    }

    private function ensure(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('You are not authorized to access this customer booking.');
        }
    }
}
