<?php

namespace App\Modules\Bookings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Requests\CancelBookingRequest;
use App\Modules\Bookings\Requests\ManageDisputeRequest;
use App\Modules\Bookings\Resources\BookingResource;
use App\Modules\Bookings\Services\BookingService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Bookings', description: 'Manage bookings (view, search, filter, cancel, manage disputes)')]
class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    /**
     * GET /api/bookings — paginated, searchable, filterable list.
     */
    #[OA\Get(
        path: '/api/bookings',
        summary: 'List all bookings',
        tags: ['Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by booking ID, client, provider, or service', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by status', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'confirmed', 'active', 'completed', 'cancelled', 'disputed'])),
            new OA\Parameter(name: 'payment_status', in: 'query', description: 'Filter by payment status', required: false, schema: new OA\Schema(type: 'string', enum: ['unpaid', 'paid', 'refunded', 'partially_refunded'])),
            new OA\Parameter(name: 'provider_id', in: 'query', description: 'Filter by provider profile ID', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'client_id', in: 'query', description: 'Filter by client user ID', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'service_id', in: 'query', description: 'Filter by service ID', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'dispute_status', in: 'query', description: 'Filter by dispute status', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'investigated', 'resolved', 'rejected', 'closed'])),
            new OA\Parameter(name: 'date_from', in: 'query', description: 'Filter bookings created from this date', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', description: 'Filter bookings created up to this date', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort column', required: false, schema: new OA\Schema(type: 'string', enum: ['booking_number', 'created_at', 'total_price', 'status', 'scheduled_date'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', description: 'Sort direction', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of bookings',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Bookings retrieved.',
                        'data' => [
                            [
                                'id' => 1,
                                'booking_number' => 'BK-2026-000001',
                                'status' => 'pending',
                                'payment_status' => 'unpaid',
                                'total_price' => 150.00,
                                'service_price' => 150.00,
                                'platform_fee' => 15.00,
                                'currency' => 'USD',
                                'scheduled_date' => '2026-09-01T10:00:00+00:00',
                                'service' => ['id' => 1, 'title' => 'Emergency Pipe Repair'],
                                'client' => ['id' => 3, 'name' => 'Alice Customer'],
                                'provider' => ['id' => 1, 'business_name' => 'Garcia Plumbing Solutions'],
                                'created_at' => '2026-08-20T12:00:00+00:00',
                            ],
                        ],
                        'errors' => null,
                        'meta' => ['pagination' => ['total' => 40, 'per_page' => 15, 'current_page' => 1, 'last_page' => 3, 'from' => 1, 'to' => 15]],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the view bookings permission',
            ),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $paginator = $this->bookingService->index($request->only([
            'search', 'status', 'payment_status', 'provider_id', 'client_id',
            'service_id', 'dispute_status', 'date_from', 'date_to',
            'sort', 'direction', 'per_page',
        ]));

        return $this->paginated($paginator, BookingResource::class, 'Bookings retrieved.');
    }

    /**
     * GET /api/bookings/{booking} — a single booking with relationships.
     */
    #[OA\Get(
        path: '/api/bookings/{booking}',
        summary: 'Get booking details',
        tags: ['Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Booking details',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Booking not found'),
        ],
    )]
    public function show(Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        $booking = $this->bookingService->show($booking);

        return $this->success(new BookingResource($booking));
    }

    /**
     * GET /api/bookings/{booking}/history — booking status change history.
     */
    #[OA\Get(
        path: '/api/bookings/{booking}/history',
        summary: 'Get booking history',
        tags: ['Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Booking history',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Booking not found'),
        ],
    )]
    public function history(Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        return $this->success(
            $this->bookingService->history($booking),
            'Booking history retrieved.',
        );
    }

    /**
     * PATCH /api/bookings/{booking}/cancel — cancel a booking.
     */
    #[OA\Patch(
        path: '/api/bookings/{booking}/cancel',
        summary: 'Cancel a booking',
        tags: ['Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                example: ['reason' => 'Client requested cancellation.'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 1000, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Booking cancelled',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Booking cancelled.',
                        'data' => ['id' => 1, 'status' => 'cancelled'],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(
                response: 422,
                description: 'Booking cannot be cancelled (state guard)',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This booking cannot be cancelled in its current status.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function cancel(CancelBookingRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('cancel', $booking);

        if (! $booking->isCancellable()) {
            return $this->error('This booking cannot be cancelled in its current status.', 422);
        }

        $booking = $this->bookingService->cancel($booking, $request->user(), $request->validated('reason'));

        return $this->success(new BookingResource($booking), 'Booking cancelled.');
    }

    /**
     * PATCH /api/bookings/{booking}/dispute — manage a booking dispute.
     */
    #[OA\Patch(
        path: '/api/bookings/{booking}/dispute',
        summary: 'Manage a booking dispute',
        tags: ['Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['action'],
                example: ['action' => 'resolve', 'resolution' => 'Refund issued to client.'],
                properties: [
                    new OA\Property(property: 'action', type: 'string', enum: ['investigate', 'resolve', 'reject']),
                    new OA\Property(property: 'resolution', type: 'string', maxLength: 2000, nullable: true),
                    new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dispute managed',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Dispute resolved.',
                        'data' => ['id' => 1, 'dispute_status' => 'resolved'],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function dispute(ManageDisputeRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('manageDispute', $booking);

        $booking = $this->bookingService->manageDispute(
            $booking,
            $request->user(),
            $request->validated('action'),
            $request->validated('resolution'),
            $request->validated('notes'),
        );

        $message = match ($request->validated('action')) {
            'investigate' => 'Dispute under investigation.',
            'resolve' => 'Dispute resolved.',
            'reject' => 'Dispute rejected.',
            default => 'Dispute updated.',
        };

        return $this->success(new BookingResource($booking), $message);
    }
}
