<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ClientMarketplace\Requests\ProviderCommissionRemittanceIndexRequest;
use App\Modules\ClientMarketplace\Resources\ProviderCommissionRemittanceResource;
use App\Modules\Commissions\Models\CommissionRemittance;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Commissions\Services\CommissionQrPaymentService;
use App\Modules\Commissions\Services\CommissionRemittanceService;
use App\Modules\Commissions\Services\TransactionEligibility;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * What the signed-in provider owes SkillServe, whether that is currently
 * stopping them taking on work, and how they pay it.
 *
 * The provider sends the commission to SkillServe's GCash outside the app and
 * submits the GCash reference number; nothing is settled until an
 * administrator confirms the money arrived. The app uses the summary to
 * explain a refusal instead of guessing at it — the API stays authoritative.
 */
#[OA\Tag(name: 'Provider Commissions', description: 'What the provider owes SkillServe and whether they can take on new work')]
class ProviderCommissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CommissionLedger $ledger,
        private readonly TransactionEligibility $eligibility,
        private readonly CommissionRemittanceService $remittances,
        private readonly CommissionQrPaymentService $qrPayments,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/provider/commissions',
        summary: "The provider's outstanding commission and transaction eligibility",
        description: "SkillServe's commission is included in the price the provider advertises, so on an on-hand job the provider collects the whole amount and owes the platform's share back. Once the block applies the provider cannot accept new jobs or publish services; declining, cancelling and finishing agreed work stay available. By default any unpaid commission blocks at once; an administrator can instead start the block at a minimum unpaid total (`block_threshold`) and/or after the oldest unpaid commission reaches an age (`block_deadline`, null when off). Whichever comes first blocks.\n\n`eligible` is false with `reason` = `outstanding_commission` when the block applies.\n\n`payment_account.qr_payments_available` says whether the provider can pay with a PayMongo QR Ph code for the exact amount (POST /provider/commission-remittances/qr) — the only way to pay. `latest_remittance` is the provider's most recent payment of either kind, or null: while it is `pending` they cannot start another (a QR one carries `qr_image` while it can be scanned); a `rejected` one carries `rejection_reason`.",
        tags: ['Provider Commissions'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Outstanding commissions and eligibility', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Commission summary retrieved.',
                    'data' => [
                        'eligible' => false,
                        'reason' => 'outstanding_commission',
                        'outstanding_total' => '20.00',
                        'outstanding_count' => 1,
                        'block_threshold' => '0.00',
                        'block_deadline' => null,
                        'currency' => 'PHP',
                        'payment_account' => [
                            'qr_payments_available' => true,
                        ],
                        'latest_remittance' => null,
                        'outstanding' => [[
                            'booking_number' => 'BK-AB12CD34EF56',
                            'service' => 'Aircon Cleaning',
                            'total_price' => '200.00',
                            'commission_rate' => '10.00',
                            'commission_amount' => '20.00',
                            'paid_at' => '2026-09-24T08:00:00+00:00',
                        ]],
                    ],
                    'errors' => null,
                    'meta' => [],
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, email-verified provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->profileOf($user);

        $state = $this->eligibility->forProvider($user);

        $outstanding = $this->ledger->outstandingQuery($profile->id)
            ->with('service:id,title')
            ->orderBy('paid_at')
            ->get()
            ->map(fn ($booking): array => [
                'booking_number' => $booking->booking_number,
                'service' => $booking->service?->title,
                'total_price' => $booking->total_price,
                'commission_rate' => $booking->commission_rate,
                'commission_amount' => $booking->platform_fee,
                'paid_at' => $booking->paid_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $latest = $this->remittances->latestFor($profile->id);

        return $this->success([
            ...$state,
            'currency' => 'PHP',
            'payment_account' => [
                'qr_payments_available' => $this->qrPayments->available(),
            ],
            'latest_remittance' => $latest ? new ProviderCommissionRemittanceResource($latest) : null,
            'outstanding' => $outstanding,
        ], 'Commission summary retrieved.');
    }

    #[OA\Get(
        path: '/api/client/v1/provider/commission-remittances',
        summary: "The provider's GCash commission payments",
        description: 'Newest first, with the status of each: pending (waiting for SkillServe to confirm), confirmed, or rejected with the reason.',
        tags: ['Provider Commissions'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated payments', content: new OA\JsonContent(
                allOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiEnvelope'),
                    new OA\Schema(properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProviderCommissionRemittance')),
                    ]),
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, email-verified provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
            new OA\Response(response: 422, description: 'Invalid per_page or page'),
        ],
    )]
    public function remittances(ProviderCommissionRemittanceIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->remittances->historyFor($this->profileOf($request->user()), $request->validated()),
            ProviderCommissionRemittanceResource::class,
            'Commission payments retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/provider/commission-remittances',
        summary: 'Retired: submit a GCash reference for the commission',
        description: 'The commission is paid only with a QR Ph code now (POST /provider/commission-remittances/qr). This older way — GCash sent outside the app, then the reference typed in — always answers 410 so an outdated app tells the provider to update. Payments already submitted this way are still reviewed by an administrator.',
        deprecated: true,
        tags: ['Provider Commissions'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, email-verified provider account required'),
            new OA\Response(response: 410, description: 'Retired; pay with a QR code instead'),
        ],
    )]
    public function storeRemittance(): JsonResponse
    {
        throw new ApiException(
            'Paying by GCash reference has ended. Update the SkillServe app and pay with a QR code.',
            410,
        );
    }

    #[OA\Post(
        path: '/api/client/v1/provider/commission-remittances/qr',
        summary: 'Get a QR Ph code to pay the outstanding commission',
        description: "PayMongo creates a single-use dynamic QR Ph code for everything the provider owes right now; the amount is worked out by the server, never sent by the app. Scan it with GCash, Maya or a bank app. `qr_image` is the code as PayMongo returns it (a base-64 image to show as-is) and `expires_at` when it stops working (15 minutes; PayMongo is asked for `expiry_seconds` 900). The payment is confirmed only by PayMongo's signed webhook, and only when its amount and currency match; then the covered commissions are settled and the provider is notified.\n\nCalling it again while a code is still payable returns that code (200) instead of making another. 503 when QR payments are not configured — pay by GCash reference instead.",
        tags: ['Provider Commissions'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 201, description: 'A new QR code', content: new OA\JsonContent(
                allOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiEnvelope'),
                    new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProviderCommissionRemittance')]),
                ],
            )),
            new OA\Response(response: 200, description: 'The QR code already open'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, email-verified provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
            new OA\Response(response: 409, description: 'A GCash-reference payment is waiting for confirmation, or a QR code is still being prepared'),
            new OA\Response(response: 422, description: 'Nothing is owed, or the amount is outside what PayMongo accepts'),
            new OA\Response(response: 429, description: 'Too many attempts'),
            new OA\Response(response: 502, description: 'PayMongo could not create the code; try again'),
            new OA\Response(response: 503, description: 'QR payments are not configured'),
        ],
    )]
    public function startQrPayment(Request $request): JsonResponse
    {
        $user = $request->user();

        [$remittance, $created] = $this->qrPayments->start($user, $this->profileOf($user));

        return $this->success(
            new ProviderCommissionRemittanceResource($remittance),
            $created ? 'Scan this QR code to pay.' : 'Your QR code is still open.',
            status: $created ? 201 : 200,
        );
    }

    #[OA\Get(
        path: '/api/client/v1/provider/commission-remittances/{commissionRemittance}',
        summary: 'One of the provider\'s commission payments',
        description: 'For the app to follow a QR payment while it waits: `status` turns `confirmed` once PayMongo confirms it, or `expired` / `failed`. Only the provider\'s own payments; anyone else\'s is a 404.',
        tags: ['Provider Commissions'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'commissionRemittance', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The payment', content: new OA\JsonContent(
                allOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiEnvelope'),
                    new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProviderCommissionRemittance')]),
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, email-verified provider account required'),
            new OA\Response(response: 404, description: 'Not found, or not this provider\'s'),
        ],
    )]
    public function showRemittance(Request $request, int $commissionRemittance): JsonResponse
    {
        $profile = $this->profileOf($request->user());
        $this->remittances->expireStaleQr($profile->id);

        // Scoped to the provider, so another provider's id is simply absent.
        $remittance = CommissionRemittance::query()
            ->where('provider_profile_id', $profile->id)
            ->withCount('bookings')
            ->findOrFail($commissionRemittance);

        return $this->success(new ProviderCommissionRemittanceResource($remittance), 'Commission payment retrieved.');
    }

    private function profileOf(User $user): ProviderProfile
    {
        return $user->providerProfile
            ?? throw new ApiException('Provider profile not found.', 404);
    }
}
