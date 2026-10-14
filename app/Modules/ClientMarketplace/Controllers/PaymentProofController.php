<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingPaymentProof;
use App\Modules\Bookings\Services\BookingPaymentProofService;
use App\Modules\ClientMarketplace\Policies\ClientBookingPolicy;
use App\Modules\ClientMarketplace\Policies\ProviderBookingPolicy;
use App\Modules\ClientMarketplace\Requests\RejectPaymentProofRequest;
use App\Modules\ClientMarketplace\Requests\StorePaymentProofRequest;
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

#[OA\Tag(name: 'Payment Proofs', description: 'GCash payment proofs: the customer sends what they paid the provider — a downpayment or the rest — with a screenshot; the provider confirms or rejects it. SkillServe never holds the money.')]
class PaymentProofController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingPaymentProofService $proofs,
        private readonly ClientBookingService $clientBookings,
        private readonly ProviderBookingService $providerBookings,
        private readonly ClientBookingPolicy $clientPolicy,
        private readonly ProviderBookingPolicy $providerPolicy,
    ) {}

    #[OA\Post(
        path: '/api/client/v1/bookings/{booking}/payment-proofs',
        summary: 'Send proof of a GCash payment to the provider',
        description: 'The customer\'s own GCash booking, once accepted, while something is still owed. `amount` is what was sent — a downpayment or up to everything still owed (`balance_due`). One proof waits for the provider at a time. Returns the booking with its `payment_proofs`.',
        tags: ['Payment Proofs'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(
            required: ['amount', 'image'],
            properties: [
                new OA\Property(property: 'amount', type: 'number', format: 'float', minimum: 1, example: 500),
                new OA\Property(property: 'reference', type: 'string', maxLength: 100, nullable: true, description: 'The GCash reference number'),
                new OA\Property(property: 'image', type: 'string', format: 'binary', description: 'Screenshot of the payment (JPG, PNG or WebP, at most 5 MB)'),
            ],
        ))),
        responses: [
            new OA\Response(response: 201, description: 'Proof sent; the provider is notified', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the customer\'s booking'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 409, description: 'Already paid, or a proof is still waiting'),
            new OA\Response(response: 422, description: 'Not a GCash booking, not accepted yet, or the amount is more than is owed'),
        ],
    )]
    public function store(StorePaymentProofRequest $request, Booking $booking): JsonResponse
    {
        $this->ensure($this->clientPolicy->view($request->user(), $booking));

        $this->proofs->submit(
            $request->user(),
            $booking,
            (float) $request->validated('amount'),
            $request->validated('reference'),
            $request->file('image'),
        );

        return $this->success(
            new ClientBookingResource($this->clientBookings->show($request->user(), $booking)),
            'Payment proof sent. The provider will confirm it.',
            status: 201,
        );
    }

    #[OA\Get(
        path: '/api/client/v1/bookings/{booking}/payment-proofs/{proof}/image',
        summary: 'The screenshot of one of the customer\'s payment proofs',
        tags: ['Payment Proofs'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'proof', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The image file'), new OA\Response(response: 403, description: 'Not the customer\'s booking'), new OA\Response(response: 404, description: 'No such proof on this booking')],
    )]
    public function customerImage(Request $request, Booking $booking, BookingPaymentProof $proof): StreamedResponse
    {
        $this->ensure($this->clientPolicy->view($request->user(), $booking));

        return $this->proofs->image($booking, $proof);
    }

    #[OA\Patch(
        path: '/api/client/v1/provider/bookings/{booking}/payment-proofs/{proof}/confirm',
        summary: 'Confirm a customer\'s GCash payment proof',
        description: 'Before or after the work. The amount is added to `amount_paid`; the booking becomes `partially_paid` (a downpayment) or `paid` once nothing is left, which also makes the commission due. Returns the booking.',
        tags: ['Payment Proofs'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'proof', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Confirmed', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Not the provider\'s booking'),
            new OA\Response(response: 404, description: 'No such proof on this booking'),
            new OA\Response(response: 409, description: 'Already reviewed, or more than is still owed'),
        ],
    )]
    public function confirm(Request $request, Booking $booking, BookingPaymentProof $proof): JsonResponse
    {
        $this->ensure($this->providerPolicy->transition($request->user(), $booking));
        $this->proofs->confirm($request->user(), $booking, $proof);

        return $this->success(
            new ProviderBookingResource($this->providerBookings->show($request->user(), $booking)),
            'Payment confirmed.',
        );
    }

    #[OA\Patch(
        path: '/api/client/v1/provider/bookings/{booking}/payment-proofs/{proof}/reject',
        summary: 'Reject a customer\'s GCash payment proof',
        description: 'Nothing is recorded as paid; the customer is told the reason and can send another.',
        tags: ['Payment Proofs'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'proof', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 500, example: 'No payment with this reference reached my GCash.')])),
        responses: [
            new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Not the provider\'s booking'),
            new OA\Response(response: 404, description: 'No such proof on this booking'),
            new OA\Response(response: 409, description: 'Already reviewed'),
            new OA\Response(response: 422, description: 'A reason is required'),
        ],
    )]
    public function reject(RejectPaymentProofRequest $request, Booking $booking, BookingPaymentProof $proof): JsonResponse
    {
        $this->ensure($this->providerPolicy->transition($request->user(), $booking));
        $this->proofs->reject($request->user(), $booking, $proof, $request->validated('reason'));

        return $this->success(
            new ProviderBookingResource($this->providerBookings->show($request->user(), $booking)),
            'Payment proof rejected.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/provider/bookings/{booking}/payment-proofs/{proof}/image',
        summary: 'The screenshot of a customer\'s payment proof',
        tags: ['Payment Proofs'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'proof', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The image file'), new OA\Response(response: 403, description: 'Not the provider\'s booking'), new OA\Response(response: 404, description: 'No such proof on this booking')],
    )]
    public function providerImage(Request $request, Booking $booking, BookingPaymentProof $proof): StreamedResponse
    {
        $this->ensure($this->providerPolicy->view($request->user(), $booking));

        return $this->proofs->image($booking, $proof);
    }

    private function ensure(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('You are not authorized to access this booking.');
        }
    }
}
