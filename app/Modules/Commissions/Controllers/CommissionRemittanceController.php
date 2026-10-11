<?php

namespace App\Modules\Commissions\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commissions\Models\CommissionRemittance;
use App\Modules\Commissions\Requests\CommissionRemittanceIndexRequest;
use App\Modules\Commissions\Requests\ConfirmCommissionRemittanceRequest;
use App\Modules\Commissions\Requests\RejectCommissionRemittanceRequest;
use App\Modules\Commissions\Resources\CommissionRemittanceResource;
use App\Modules\Commissions\Services\CommissionRemittanceService;
use App\Shared\Helpers\StoredFile;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GCash commission payments waiting for an administrator: providers pay
 * SkillServe's GCash outside the app and submit the reference number; a
 * reviewer finds it in SkillServe's GCash history and confirms (settling the
 * bookings it covered) or rejects it with a reason.
 *
 * Reading needs "view commissions"; deciding needs "settle commissions".
 */
#[OA\Tag(name: 'Commission Remittances', description: 'Confirm or reject the GCash commission payments providers submit')]
class CommissionRemittanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CommissionRemittanceService $remittances) {}

    #[OA\Get(
        path: '/api/commission-remittances',
        summary: 'List GCash commission payments',
        description: 'Each item carries the GCash reference to look for in SkillServe\'s GCash history, the amount owed and the amount the provider says they sent, the provider\'s own GCash account (the expected sender), and the bookings a confirmation would settle. `other_claims_count` above 0 means another provider has a pending or confirmed claim on the same reference — check the sender before confirming. Pass status=pending for the review queue.',
        tags: ['Commission Remittances'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: CommissionRemittance::STATUSES)),
            new OA\Parameter(name: 'provider_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search by reference number or business name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, description: 'By submission time', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated payments', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the view commissions permission'),
            new OA\Response(response: 422, description: 'Invalid filter values'),
        ],
    )]
    public function index(CommissionRemittanceIndexRequest $request): JsonResponse
    {
        $this->authorize('view commissions');

        return $this->paginated(
            $this->remittances->index($request->validated()),
            CommissionRemittanceResource::class,
            'Commission payments retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/commission-remittances/{commissionRemittance}/proof',
        summary: 'Download the GCash receipt a provider attached',
        description: 'Served from private storage after checking the view commissions permission, and logged to the audit trail. 404 when no receipt was attached, or once it was deleted 180 days after the payment was decided.',
        tags: ['Commission Remittances'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'commissionRemittance', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The receipt image'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the view commissions permission'),
            new OA\Response(response: 404, description: 'Payment or receipt not found'),
        ],
    )]
    public function proof(CommissionRemittance $commissionRemittance): StreamedResponse
    {
        $this->authorize('view commissions');

        abort_if($commissionRemittance->proof_path === null, 404);

        $disk = Storage::disk(CommissionRemittanceService::DISK);
        StoredFile::ensureExists($disk, $commissionRemittance->proof_path, 'This receipt is no longer stored on the server.');

        // Receipts show a person's GCash name and number, so who looked is kept.
        activity('commissions')
            ->causedBy(request()->user())
            ->performedOn($commissionRemittance)
            ->log('commission_remittance_receipt_opened');

        return $disk->download($commissionRemittance->proof_path, basename($commissionRemittance->proof_path), [
            'Content-Type' => $commissionRemittance->proof_mime_type ?? 'application/octet-stream',
        ]);
    }

    #[OA\Patch(
        path: '/api/commission-remittances/{commissionRemittance}/confirm',
        summary: 'Confirm a GCash commission payment arrived',
        description: 'Only a pending payment can be confirmed. Settles every booking it covered that is still outstanding, recording method gcash and the payment\'s reference, and tells the provider. A booking waived, voided or settled by hand meanwhile is skipped. A reference pays once: confirming a second claim on an already-confirmed reference is refused with 409.',
        tags: ['Commission Remittances'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'commissionRemittance', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true)],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Payment confirmed', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the settle commissions permission'),
            new OA\Response(response: 404, description: 'Payment not found'),
            new OA\Response(response: 409, description: 'The payment has already been reviewed, or its reference has already been confirmed on another claim'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function confirm(ConfirmCommissionRemittanceRequest $request, CommissionRemittance $commissionRemittance): JsonResponse
    {
        $this->authorize('settle commissions');

        return $this->success(
            new CommissionRemittanceResource(
                $this->remittances->confirm($commissionRemittance, $request->user(), $request->validated('notes')),
            ),
            'Payment confirmed.',
        );
    }

    #[OA\Patch(
        path: '/api/commission-remittances/{commissionRemittance}/reject',
        summary: 'Reject a GCash commission payment',
        description: 'Only a pending payment can be rejected, and a reason is required — the provider is shown it and may submit again. Nothing is settled.',
        tags: ['Commission Remittances'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'commissionRemittance', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000, example: 'Reference number not found in our GCash history.')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Payment rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the settle commissions permission'),
            new OA\Response(response: 404, description: 'Payment not found'),
            new OA\Response(response: 409, description: 'The payment has already been reviewed'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function reject(RejectCommissionRemittanceRequest $request, CommissionRemittance $commissionRemittance): JsonResponse
    {
        $this->authorize('settle commissions');

        return $this->success(
            new CommissionRemittanceResource(
                $this->remittances->reject($commissionRemittance, $request->user(), $request->validated('reason')),
            ),
            'Payment rejected.',
        );
    }
}
