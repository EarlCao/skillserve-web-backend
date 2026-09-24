<?php

namespace App\Modules\Commissions\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Requests\CommissionIndexRequest;
use App\Modules\Commissions\Requests\SettleCommissionRequest;
use App\Modules\Commissions\Requests\WaiveCommissionRequest;
use App\Modules\Commissions\Resources\CommissionResource;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The commission ledger: what each booking earned SkillServe, and whether the
 * provider has remitted it.
 *
 * SkillServe's commission is included in the price the provider advertises,
 * so on an on-hand job the provider collects the whole amount in cash and owes
 * the platform's share back. Settlement is *recorded* here — the money moves
 * off-platform, exactly as booking payments do (see ADR-007).
 */
#[OA\Tag(name: 'Commissions', description: "Track and settle SkillServe's share of each booking")]
class CommissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CommissionLedger $ledger,
    ) {}

    #[OA\Get(
        path: '/api/commissions',
        summary: 'List booking commissions',
        description: "commission_status is pending (the job has not been paid for), outstanding (the provider holds SkillServe's share), settled, waived or voided. meta.totals carries the outstanding, settled and waived sums for the same provider and date filters.",
        tags: ['Commissions'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: CommissionIndexRequest::STATUSES)),
            new OA\Parameter(name: 'provider_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search by booking number', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Earliest payment date', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Latest payment date', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['paid_at', 'platform_fee', 'created_at'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated commissions with totals', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the view commissions permission'),
            new OA\Response(response: 422, description: 'Invalid filter values'),
        ],
    )]
    public function index(CommissionIndexRequest $request): JsonResponse
    {
        $this->authorize('view commissions');

        $filters = $request->validated();

        return $this->paginated(
            $this->ledger->index($filters),
            CommissionResource::class,
            'Commissions retrieved.',
            meta: ['totals' => $this->ledger->totals($filters)],
        );
    }

    #[OA\Patch(
        path: '/api/commissions/{booking}/settle',
        summary: 'Record that a provider remitted the commission',
        description: 'Only an outstanding commission can be settled. The amount is always the commission snapshotted on the booking — it is never taken from the request, so a client cannot influence what SkillServe books as revenue.',
        tags: ['Commissions'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['method'],
            properties: [
                new OA\Property(property: 'method', type: 'string', enum: ['gcash', 'bank_transfer', 'cash', 'offset', 'other']),
                new OA\Property(property: 'reference', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Settlement recorded', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the settle commissions permission'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 409, description: 'The commission is not outstanding'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function settle(SettleCommissionRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('settle commissions');

        $this->ledger->settle(
            $booking,
            $request->user(),
            $request->validated('method'),
            $request->validated('reference'),
            $request->validated('notes'),
        );

        return $this->success(
            new CommissionResource($this->reload($booking)),
            'Commission settled.',
        );
    }

    #[OA\Patch(
        path: '/api/commissions/{booking}/waive',
        summary: 'Write off an outstanding commission',
        description: 'Only an outstanding commission can be waived, and a reason is always required — it is written to the audit log alongside the administrator who approved it.',
        tags: ['Commissions'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000)],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Commission waived', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the settle commissions permission'),
            new OA\Response(response: 404, description: 'Booking not found'),
            new OA\Response(response: 409, description: 'The commission is not outstanding'),
            new OA\Response(response: 422, description: 'A reason is required'),
        ],
    )]
    public function waive(WaiveCommissionRequest $request, Booking $booking): JsonResponse
    {
        $this->authorize('settle commissions');

        $this->ledger->waive($booking, $request->user(), $request->validated('reason'));

        return $this->success(
            new CommissionResource($this->reload($booking)),
            'Commission waived.',
        );
    }

    private function reload(Booking $booking): Booking
    {
        return $booking->fresh([
            'provider:id,business_name,user_id',
            'service:id,title',
            'client:id,name',
            'commissionSettlement.settledBy:id,name',
        ]);
    }
}
