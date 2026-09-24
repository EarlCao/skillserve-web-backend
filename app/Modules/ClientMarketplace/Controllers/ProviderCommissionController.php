<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Commissions\Services\TransactionEligibility;
use App\Shared\Exceptions\ApiException;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * What the signed-in provider owes SkillServe, and whether that is currently
 * stopping them taking on work.
 *
 * Read-only: no payment gateway is wired up yet, so a remittance is recorded
 * by an administrator once it arrives (see ADR-007). The app uses this to
 * explain a refusal instead of guessing at it — the API stays authoritative.
 */
#[OA\Tag(name: 'Provider Commissions', description: 'What the provider owes SkillServe and whether they can take on new work')]
class ProviderCommissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CommissionLedger $ledger,
        private readonly TransactionEligibility $eligibility,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/provider/commissions',
        summary: "The provider's outstanding commission and transaction eligibility",
        description: "SkillServe's commission is included in the price the provider advertises, so on an on-hand job the provider collects the whole amount and owes the platform's share back. While anything is outstanding the provider cannot accept or start jobs, or publish services; declining and cancelling stay available so they can still clear their queue.\n\n`eligible` is false with `reason` = `outstanding_commission` when the block applies.",
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
                        'currency' => 'PHP',
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

        $profile = $user->providerProfile
            ?? throw new ApiException('Provider profile not found.', 404);

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

        return $this->success([
            ...$state,
            'currency' => 'PHP',
            'outstanding' => $outstanding,
        ], 'Commission summary retrieved.');
    }
}
