<?php

namespace App\Modules\IdentityVerification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commissions\Services\TransactionEligibility;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Whether the signed-in account may currently transact, and if not, why.
 *
 * This exists so the app can *explain* a refusal instead of guessing at it.
 * It is not the security boundary — every protected action re-checks these
 * rules server-side, so a client that ignores this response simply receives a
 * 403 from the action itself.
 */
#[OA\Tag(name: 'Transaction Eligibility', description: 'Whether the signed-in account may transact, and what is blocking it')]
class TransactionEligibilityController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TransactionEligibility $eligibility,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/transaction-eligibility',
        summary: 'Whether this account may transact, and what is blocking it',
        description: "Returns the same decision the protected endpoints enforce, so the app can show the right prompt rather than a bare 403.\n\n`reason` is null when eligible, otherwise one of `identity_unverified`, `identity_pending`, `identity_rejected`, `outstanding_commission` or `no_provider_profile`. Providers additionally receive their outstanding commission total.\n\n`identity_required` reflects System Settings → Identity: accounts created before the grandfathering date are not required to verify and report `false`.\n\nThis endpoint is advisory. The backend remains authoritative: the mobile app and the admin web both inherit these rules from the API rather than implementing them separately.",
        tags: ['Transaction Eligibility'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Eligibility', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Eligibility retrieved.',
                    'data' => [
                        'account_type' => 'provider',
                        'eligible' => false,
                        'reason' => 'identity_unverified',
                        'identity_status' => 'unverified',
                        'identity_required' => true,
                        'outstanding_total' => '0.00',
                        'outstanding_count' => 0,
                    ],
                    'errors' => null,
                    'meta' => [],
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, email-verified account required'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $state = $user->isMobileProviderAccount()
            ? $this->eligibility->forProvider($user)
            : $this->eligibility->forCustomer($user);

        return $this->success(
            ['account_type' => $user->user_type, ...$state],
            'Eligibility retrieved.',
        );
    }
}
