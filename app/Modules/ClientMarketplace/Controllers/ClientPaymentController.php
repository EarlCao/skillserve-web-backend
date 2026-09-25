<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Enums\PaymentMethod;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingPaymentService;
use App\Modules\Commissions\Services\TransactionEligibility;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Shared\Exceptions\ApiException;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * Starts an online payment for the customer's own booking.
 *
 * Returns the URL the app sends the customer to. It does **not** mark the
 * booking paid — that happens when PayMongo calls the webhook, because the
 * customer's return from the payment page is a browser redirect and cannot be
 * trusted with money.
 */
#[OA\Tag(name: 'Client Payments', description: 'Pay for a booking online')]
class ClientPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly TransactionEligibility $eligibility,
    ) {}

    #[OA\Post(
        path: '/api/client/v1/bookings/{booking}/pay',
        summary: 'Start an online payment for a booking',
        description: "Available on the customer's own unpaid booking once it is confirmed, active, completed or disputed, and only when the booking's payment method maps to a gateway that collects money (today: `gcash`, once PayMongo is configured). An `on_hand` booking is refused — it is settled in person.\n\nReturns `redirect_url`; send the customer there. The booking becomes paid when PayMongo calls the webhook, **not** when the customer returns, so poll the booking afterwards rather than assuming success.\n\nTapping pay again returns the same in-flight payment rather than starting a second one.",
        tags: ['Client Payments'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Reuse across retries of the same attempt', schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment started', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Payment started.',
                    'data' => [
                        'redirect_url' => 'https://secure-authentication.paymongo.com/sources?id=src_...',
                        'status' => 'awaiting_next_action',
                        'amount' => '200.00',
                        'currency' => 'PHP',
                    ],
                    'errors' => null,
                    'meta' => [],
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the customer on this booking, or identity not verified'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 409, description: 'Already paid'),
            new OA\Response(response: 422, description: 'Not payable online, or wrong status'),
            new OA\Response(response: 502, description: 'The payment provider refused the request'),
        ],
    )]
    public function store(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        abort_unless($booking->client_id === $user->id, 403);

        $this->eligibility->assertIdentityVerified($user);

        if ($booking->payment_status !== 'unpaid') {
            throw new ApiException(
                'This booking has already been paid for.',
                409,
                errors: ['payment_status' => ['The payment status is '.$booking->payment_status.'.']],
            );
        }

        if (! in_array($booking->status, BookingPaymentService::PAYABLE_STATUSES, true)) {
            throw new ApiException(
                'This booking cannot be paid for yet.',
                422,
                errors: ['status' => ['This booking is '.$booking->status.'; wait until the provider accepts it.']],
            );
        }

        $method = PaymentMethod::fromInput($booking->payment_method);

        if ($method === null) {
            throw new ApiException('This booking has no online payment method.', 422);
        }

        $gateway = $this->gateways->for($method);

        if (! $gateway->collectsPayment()) {
            throw new ApiException(
                'This booking is settled in person, not online.',
                422,
                errors: ['payment_method' => [$method->label().' is paid directly to the provider.']],
            );
        }

        // A stable key per booking attempt: a retried request resumes the same
        // payment instead of starting a second one.
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'))
            ?: 'bk-'.$booking->id.'-'.Str::uuid();

        $result = $gateway->collect($booking, $idempotencyKey);

        return $this->success([
            'redirect_url' => $result['redirect_url'],
            'status' => $result['status'],
            'amount' => number_format((float) $booking->total_price, 2, '.', ''),
            'currency' => $booking->currency ?: 'PHP',
        ], 'Payment started.');
    }
}
