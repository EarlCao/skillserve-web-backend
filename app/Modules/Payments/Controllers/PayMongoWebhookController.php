<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Gateways\PayMongoGateway;
use App\Modules\Payments\Services\PayMongoWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * PayMongo's webhook endpoint.
 *
 * Unauthenticated by necessity — PayMongo has no SkillServe token — so the
 * signature is the only thing separating a genuine event from a forged one.
 * It is checked against the **raw** body before anything is parsed, because
 * re-encoding JSON changes bytes and would break the check on real requests.
 *
 * An invalid signature returns 401 and is never processed. A valid event is
 * always answered 200, even when SkillServe cannot act on it: a non-2xx makes
 * PayMongo retry, and retrying will not fix an event for a booking that no
 * longer exists.
 */
#[OA\Tag(name: 'Payment Webhooks', description: 'Signed callbacks from the payment provider')]
class PayMongoWebhookController extends Controller
{
    public function __construct(
        private readonly PayMongoGateway $gateway,
        private readonly PayMongoWebhookProcessor $processor,
    ) {}

    #[OA\Post(
        path: '/api/webhooks/paymongo',
        summary: 'PayMongo webhook receiver',
        description: "Called by PayMongo, not by SkillServe clients. Verifies the `Paymongo-Signature` header (`t=<unix>,te=<test>,li=<live>`; HMAC-SHA256 of `<t>.<raw body>` under the webhook secret) against the raw request body, then applies `payment.paid` / `payment.failed` to the booking.\n\nA booking is marked paid **here**, never from the customer's return redirect. Events are deduplicated by event id, because PayMongo redelivers.",
        tags: ['Payment Webhooks'],
        responses: [
            new OA\Response(response: 200, description: 'Event accepted (or acknowledged and ignored)'),
            new OA\Response(response: 401, description: 'Missing or invalid signature — the event is discarded'),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Paymongo-Signature', '');

        if (! $this->gateway->verifyWebhook($payload, $signature)) {
            Log::warning('Rejected a PayMongo webhook with an invalid signature.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $decoded = json_decode($payload, true);

        if (is_array($decoded) && isset($decoded['data'])) {
            $this->processor->handle($decoded['data']);
        }

        // Always 200 once the signature is good: a retry cannot improve an
        // event SkillServe has no booking for.
        return response()->json(['received' => true]);
    }
}
