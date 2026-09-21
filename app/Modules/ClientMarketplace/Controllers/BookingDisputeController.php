<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientMarketplace\Policies\BookingDisputePolicy;
use App\Modules\ClientMarketplace\Requests\BookingDisputeIndexRequest;
use App\Modules\ClientMarketplace\Requests\DisputeBookingRequest;
use App\Modules\ClientMarketplace\Requests\StoreDisputeEvidenceRequest;
use App\Modules\ClientMarketplace\Resources\BookingDisputeResource;
use App\Modules\ClientMarketplace\Services\BookingDisputeService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Booking Disputes', description: 'Disputes raised by a booking\'s customer or provider')]
class BookingDisputeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingDisputeService $disputeService,
        private readonly BookingDisputePolicy $disputePolicy,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/disputes',
        summary: 'List the disputes on the signed-in account\'s bookings',
        tags: ['Booking Disputes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'dispute_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'investigated', 'resolved', 'rejected', 'closed'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Disputes', content: new OA\JsonContent(ref: '#/components/schemas/BookingDisputeListEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active customer or provider account required'),
            new OA\Response(response: 422, description: 'Invalid filter'),
        ],
    )]
    public function index(BookingDisputeIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->disputeService->index($request->user(), $request->validated()),
            BookingDisputeResource::class,
            'Disputes retrieved.',
        );
    }

    #[OA\Patch(
        path: '/api/client/v1/bookings/{booking}/dispute',
        summary: 'Raise a dispute on a booking',
        description: 'Opens a case for administrators to review. Only a job in progress or completed can be disputed, and only once. The booking moves to the "disputed" status and the other party is notified.',
        tags: ['Booking Disputes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 2000)],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Dispute raised', content: new OA\JsonContent(ref: '#/components/schemas/BookingDisputeEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a party to this booking'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 409, description: 'Already under dispute'),
            new OA\Response(response: 422, description: 'Booking cannot be disputed in its current status'),
        ],
    )]
    public function store(DisputeBookingRequest $request, Booking $booking): JsonResponse
    {
        if (! $this->disputePolicy->raise($request->user(), $booking)) {
            throw new AuthorizationException('Only the customer or provider on this booking can dispute it.');
        }

        return $this->success(
            new BookingDisputeResource(
                $this->disputeService->raise($request->user(), $booking, $request->validated('reason')),
            ),
            'Dispute raised. Our support team will review it.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/bookings/{booking}/dispute/evidence',
        summary: 'Attach a photo to an open dispute',
        description: 'Either party may add evidence while the dispute is pending or under investigation, up to 5 photos per dispute. Files are kept in private storage; only administrators reviewing the case can open them.',
        tags: ['Booking Disputes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['image'],
                properties: [
                    new OA\Property(property: 'image', type: 'string', format: 'binary', description: 'JPG, PNG or WebP, up to 5 MB'),
                    new OA\Property(property: 'caption', type: 'string', nullable: true, maxLength: 500),
                ],
            ),
        )),
        responses: [
            new OA\Response(response: 201, description: 'Evidence attached', content: new OA\JsonContent(ref: '#/components/schemas/BookingDisputeEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a party to this booking'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 422, description: 'No open dispute, the evidence limit is reached, or the image is invalid'),
        ],
    )]
    public function storeEvidence(StoreDisputeEvidenceRequest $request, Booking $booking): JsonResponse
    {
        if (! $this->disputePolicy->raise($request->user(), $booking)) {
            throw new AuthorizationException('Only the customer or provider on this booking can add evidence.');
        }

        $this->disputeService->addEvidence(
            $request->user(),
            $booking,
            $request->file('image'),
            $request->validated('caption'),
        );

        return $this->success(
            new BookingDisputeResource($booking->fresh()->load(['service:id,provider_id,title', 'provider:id,business_name'])),
            'Evidence added to the dispute.',
            status: 201,
        );
    }
}
