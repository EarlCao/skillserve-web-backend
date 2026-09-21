<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Requests\MarkBookingPaidRequest;
use App\Modules\ClientMarketplace\Policies\ProviderBookingPolicy;
use App\Modules\ClientMarketplace\Requests\CancelProviderBookingRequest;
use App\Modules\ClientMarketplace\Requests\DeclineProviderBookingRequest;
use App\Modules\ClientMarketplace\Requests\ProviderBookingIndexRequest;
use App\Modules\ClientMarketplace\Resources\ProviderBookingResource;
use App\Modules\ClientMarketplace\Services\ProviderBookingService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Provider Bookings', description: 'Bookings placed with the signed-in provider')]
class ProviderBookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderBookingService $bookingService,
        private readonly ProviderBookingPolicy $bookingPolicy,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/provider/bookings',
        summary: 'List the bookings placed with the authenticated provider',
        tags: ['Provider Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'confirmed', 'active', 'completed', 'cancelled', 'disputed'])),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'scheduled_date', 'status', 'total_price'], default: 'scheduled_date')),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Provider bookings', content: new OA\JsonContent(ref: '#/components/schemas/ProviderBookingListEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified provider access required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
            new OA\Response(response: 422, description: 'Invalid filter'),
        ],
    )]
    public function index(ProviderBookingIndexRequest $request): JsonResponse
    {
        $this->ensure($this->bookingPolicy->viewAny($request->user()));

        return $this->paginated(
            $this->bookingService->index($request->user(), $request->validated()),
            ProviderBookingResource::class,
            'Bookings retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/provider/bookings/{booking}',
        summary: 'Get a booking placed with the authenticated provider',
        tags: ['Provider Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Booking details', content: new OA\JsonContent(ref: '#/components/schemas/ProviderBookingEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not placed with this provider'),
            new OA\Response(response: 404, description: 'Booking not found'),
        ],
    )]
    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->bookingPolicy->view($request->user(), $booking));

        return $this->success(
            new ProviderBookingResource($this->bookingService->show($request->user(), $booking)),
            'Booking retrieved.',
        );
    }

    #[OA\Patch(
        path: '/api/client/v1/provider/bookings/{booking}/confirm',
        summary: 'Accept a pending booking request',
        tags: ['Provider Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Booking confirmed', content: new OA\JsonContent(ref: '#/components/schemas/ProviderBookingEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not placed with this provider'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 422, description: 'Only a pending booking can be accepted'),
        ],
    )]
    public function confirm(Request $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->bookingPolicy->transition($request->user(), $booking));

        return $this->success(
            new ProviderBookingResource($this->bookingService->confirm($request->user(), $booking)),
            'Booking accepted.',
        );
    }

    #[OA\Patch(
        path: '/api/client/v1/provider/bookings/{booking}/decline',
        summary: 'Decline a pending booking request',
        tags: ['Provider Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 1000, description: 'Shown to the customer as the cancellation reason.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Booking declined. The booking is cancelled; payment state is unchanged and no external refund is processed.', content: new OA\JsonContent(ref: '#/components/schemas/ProviderBookingEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not placed with this provider'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 422, description: 'Only a pending booking can be declined'),
        ],
    )]
    public function decline(DeclineProviderBookingRequest $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->bookingPolicy->transition($request->user(), $booking));

        return $this->success(
            new ProviderBookingResource(
                $this->bookingService->decline($request->user(), $booking, $request->validated('reason')),
            ),
            'Booking declined.',
        );
    }

    #[OA\Patch(
        path: '/api/client/v1/provider/bookings/{booking}/cancel',
        summary: 'Cancel an accepted booking before the job starts',
        description: 'Only a confirmed booking can be cancelled this way; use decline for a pending request. The customer is notified with the reason.',
        tags: ['Provider Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 1000, description: 'Shown to the customer as the cancellation reason.', example: 'I am unwell and cannot make it that day.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Booking cancelled. Payment state is unchanged and no external refund is processed.', content: new OA\JsonContent(ref: '#/components/schemas/ProviderBookingEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not placed with this provider'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 422, description: 'Reason missing, or the booking is not confirmed'),
        ],
    )]
    public function cancel(CancelProviderBookingRequest $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->bookingPolicy->transition($request->user(), $booking));

        return $this->success(
            new ProviderBookingResource(
                $this->bookingService->cancel($request->user(), $booking, $request->validated('reason')),
            ),
            'Booking cancelled.',
        );
    }

    #[OA\Patch(
        path: '/api/client/v1/provider/bookings/{booking}/start',
        summary: 'Start a confirmed job',
        tags: ['Provider Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Job started', content: new OA\JsonContent(ref: '#/components/schemas/ProviderBookingEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not placed with this provider'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 422, description: 'Only a confirmed booking can be started'),
        ],
    )]
    public function start(Request $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->bookingPolicy->transition($request->user(), $booking));

        return $this->success(
            new ProviderBookingResource($this->bookingService->start($request->user(), $booking)),
            'Job started.',
        );
    }

    #[OA\Patch(
        path: '/api/client/v1/provider/bookings/{booking}/complete',
        summary: 'Mark a job in progress as completed',
        tags: ['Provider Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Job completed. Payment state is unchanged because no payment provider is called.', content: new OA\JsonContent(ref: '#/components/schemas/ProviderBookingEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not placed with this provider'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 422, description: 'Only a job in progress can be completed'),
        ],
    )]
    public function complete(Request $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->bookingPolicy->transition($request->user(), $booking));

        return $this->success(
            new ProviderBookingResource($this->bookingService->complete($request->user(), $booking)),
            'Job completed.',
        );
    }

    #[OA\Patch(
        path: '/api/client/v1/provider/bookings/{booking}/payment-received',
        summary: 'Confirm the customer paid for a completed job',
        description: 'Records an off-platform payment (cash, GCash, …); nothing is charged. Only a completed, unpaid booking. The customer is notified.',
        tags: ['Provider Bookings'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'payment_reference', type: 'string', maxLength: 100, nullable: true, description: 'e.g. a GCash reference number', example: 'GCASH-0123456789'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Payment recorded; payment_status is now paid', content: new OA\JsonContent(ref: '#/components/schemas/ProviderBookingEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not placed with this provider'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 409, description: 'Already marked as paid'),
            new OA\Response(response: 422, description: 'The job is not completed yet'),
        ],
    )]
    public function paymentReceived(MarkBookingPaidRequest $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->bookingPolicy->transition($request->user(), $booking));

        return $this->success(
            new ProviderBookingResource(
                $this->bookingService->recordPayment($request->user(), $booking, $request->validated('payment_reference')),
            ),
            'Payment recorded.',
        );
    }

    private function ensure(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('You are not authorized to access this booking.');
        }
    }
}
