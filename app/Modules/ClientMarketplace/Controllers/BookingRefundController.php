<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingRefundService;
use App\Modules\ClientMarketplace\Policies\ClientBookingPolicy;
use App\Modules\ClientMarketplace\Policies\ProviderBookingPolicy;
use App\Modules\ClientMarketplace\Requests\MarkRefundSentRequest;
use App\Modules\ClientMarketplace\Requests\ReportRefundNotReceivedRequest;
use App\Modules\ClientMarketplace\Resources\ClientBookingResource;
use App\Modules\ClientMarketplace\Resources\ProviderBookingResource;
use App\Modules\ClientMarketplace\Services\ClientBookingService;
use App\Modules\ClientMarketplace\Services\ProviderBookingService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Tag(name: 'Booking Refunds', description: 'A cancelled booking\'s payment (a downpayment or all of it) goes back to the customer: the provider returns it with proof by the deadline, the customer confirms it or reports it missing, and SkillServe settles the rest. A provider with an overdue or disputed refund cannot take new bookings.')]
class BookingRefundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingRefundService $refunds,
        private readonly ClientBookingService $clientBookings,
        private readonly ProviderBookingService $providerBookings,
        private readonly ClientBookingPolicy $clientPolicy,
        private readonly ProviderBookingPolicy $providerPolicy,
    ) {}

    #[OA\Post(
        path: '/api/client/v1/provider/bookings/{booking}/refund',
        summary: 'Mark the customer\'s payment as returned, with proof',
        description: 'Multipart: `image` (required screenshot of the GCash transfer) and optional `reference`. Allowed while the refund is `due` or after the customer reported it `not_received` (a new proof). Returns the booking.',
        tags: ['Booking Refunds'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(
            required: ['image'],
            properties: [
                new OA\Property(property: 'reference', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'image', type: 'string', format: 'binary'),
            ],
        ))),
        responses: [
            new OA\Response(response: 200, description: 'Marked as returned; the customer is asked to confirm', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Not the provider\'s booking'),
            new OA\Response(response: 404, description: 'Nothing to return on this booking'),
            new OA\Response(response: 409, description: 'Not waiting to be returned'),
            new OA\Response(response: 422, description: 'Screenshot missing or invalid'),
        ],
    )]
    public function markSent(MarkRefundSentRequest $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->providerPolicy->transition($request->user(), $booking));
        $this->refunds->markSent($request->user(), $booking, $request->validated('reference'), $request->file('image'));

        return $this->success(new ProviderBookingResource($this->providerBookings->show($request->user(), $booking)), 'Marked as returned. The customer will confirm it.');
    }

    #[OA\Patch(
        path: '/api/client/v1/bookings/{booking}/refund/confirm',
        summary: 'Confirm the returned payment reached you',
        tags: ['Booking Refunds'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Confirmed; recorded as refunded on the booking', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Not the customer\'s booking'),
            new OA\Response(response: 404, description: 'Nothing to return on this booking'),
            new OA\Response(response: 409, description: 'The provider has not marked it returned'),
        ],
    )]
    public function confirm(Request $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->clientPolicy->view($request->user(), $booking));
        $this->refunds->confirm($request->user(), $booking);

        return $this->success(new ClientBookingResource($this->clientBookings->show($request->user(), $booking)), 'Thank you — the refund is confirmed.');
    }

    #[OA\Patch(
        path: '/api/client/v1/bookings/{booking}/refund/not-received',
        summary: 'Report that the returned payment did not arrive',
        description: 'SkillServe\'s team is alerted and the provider cannot take new bookings until it is settled.',
        tags: ['Booking Refunds'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['note'], properties: [new OA\Property(property: 'note', type: 'string', minLength: 10, maxLength: 1000, example: 'Nothing arrived in my GCash; the reference does not exist.')])),
        responses: [
            new OA\Response(response: 200, description: 'Reported', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Not the customer\'s booking'),
            new OA\Response(response: 404, description: 'Nothing to return on this booking'),
            new OA\Response(response: 409, description: 'The provider has not marked it returned'),
            new OA\Response(response: 422, description: 'Explain what happened (at least 10 characters)'),
        ],
    )]
    public function notReceived(ReportRefundNotReceivedRequest $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->clientPolicy->view($request->user(), $booking));
        $this->refunds->reportNotReceived($request->user(), $booking, $request->validated('note'));

        return $this->success(new ClientBookingResource($this->clientBookings->show($request->user(), $booking)), 'Reported. Our team will look into it.');
    }

    #[OA\Get(
        path: '/api/client/v1/bookings/{booking}/refund/proof',
        summary: 'The provider\'s proof of returning the payment',
        tags: ['Booking Refunds'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The image file'), new OA\Response(response: 403, description: 'Not the customer\'s booking'), new OA\Response(response: 404, description: 'No proof yet')],
    )]
    public function customerProof(Request $request, Booking $booking): StreamedResponse
    {
        $this->ensure($this->clientPolicy->view($request->user(), $booking));

        return $this->refunds->proof($booking);
    }

    #[OA\Get(
        path: '/api/client/v1/provider/bookings/{booking}/refund/proof',
        summary: 'Your proof of returning the customer\'s payment',
        tags: ['Booking Refunds'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The image file'), new OA\Response(response: 403, description: 'Not the provider\'s booking'), new OA\Response(response: 404, description: 'No proof yet')],
    )]
    public function providerProof(Request $request, Booking $booking): StreamedResponse
    {
        $this->ensure($this->providerPolicy->view($request->user(), $booking));

        return $this->refunds->proof($booking);
    }

    private function ensure(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('You are not authorized to access this booking.');
        }
    }
}
