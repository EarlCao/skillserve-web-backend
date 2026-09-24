<?php

namespace App\Modules\IdentityVerification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\IdentityVerification\Requests\SubmitIdentityVerificationRequest;
use App\Modules\IdentityVerification\Resources\IdentityVerificationResource;
use App\Modules\IdentityVerification\Services\IdentityVerificationService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The account holder submits their Philippine National ID and follows the
 * review. Customers and providers use the same endpoints — identity is
 * identity — so this sits on the shared mobile account surface.
 *
 * Distinct from provider verification (`/provider/verification`), which proves
 * a provider is a legitimate tradesperson and gates publishing services.
 */
#[OA\Tag(name: 'Identity Verification', description: 'The signed-in account holder submits their Philippine National ID and follows the review')]
class ClientIdentityVerificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly IdentityVerificationService $verification,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/identity-verification',
        summary: "The account's identity verification status",
        description: 'status is unverified, pending, verified or rejected. can_submit tells the app whether to offer the form. Only the last four digits of the card number are ever returned.',
        tags: ['Identity Verification'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Verification state', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Identity verification status retrieved.',
                    'data' => [
                        'status' => 'rejected',
                        'can_submit' => true,
                        'id_number_last4' => '4821',
                        'full_name' => 'Juan Dela Cruz',
                        'birthdate' => '1995-04-02',
                        'submitted_at' => '2026-09-20T02:11:00+00:00',
                        'reviewed_at' => '2026-09-21T06:40:00+00:00',
                        'rejection_reason' => 'The photo of the back of the card was unreadable.',
                        'documents' => [],
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
        return $this->success(
            new IdentityVerificationResource($this->verification->show($request->user())),
            'Identity verification status retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/identity-verification',
        summary: 'Submit a Philippine National ID for review',
        description: "Allowed while unverified or rejected; a pending or verified account is refused with 422. The card number is normalised to 16 digits, so grouped input is accepted.\n\nA National ID can back only one active SkillServe account: submitting one already linked to another live account returns 422 without revealing which account holds it. An ID becomes available again only once the original account is permanently deleted.\n\nFiles are private (JPG, PNG or PDF, up to 10 MB each) and are never served directly; an administrator opens them through an authorised download.",
        tags: ['Identity Verification'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['id_number', 'full_name', 'birthdate', 'documents'],
                properties: [
                    new OA\Property(property: 'id_number', type: 'string', example: '1234-5678-9012-3456', description: 'PhilSys Card Number; 16 digits, grouping optional'),
                    new OA\Property(property: 'full_name', type: 'string', maxLength: 255, description: 'Exactly as printed on the card'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date'),
                    new OA\Property(property: 'documents[0][type]', type: 'string', enum: ['id_front', 'id_back', 'selfie']),
                    new OA\Property(property: 'documents[0][file]', type: 'string', format: 'binary'),
                ],
            ),
        )),
        responses: [
            new OA\Response(response: 201, description: 'Submitted; status is now pending', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, email-verified account required'),
            new OA\Response(response: 422, description: 'Validation failed, the ID is already linked to an account, or the account is already pending or verified'),
            new OA\Response(response: 429, description: 'Too many submissions'),
        ],
    )]
    public function store(SubmitIdentityVerificationRequest $request): JsonResponse
    {
        $documents = collect($request->validated('documents'))
            ->map(fn (array $document, int $index): array => [
                'type' => $document['type'],
                'file' => $request->file("documents.{$index}.file"),
            ])
            ->values()
            ->all();

        return $this->success(
            new IdentityVerificationResource($this->verification->submit(
                $request->user(),
                [
                    'id_number' => $request->validated('id_number'),
                    'full_name' => $request->validated('full_name'),
                    'birthdate' => $request->validated('birthdate'),
                ],
                $documents,
            )),
            'National ID submitted for review.',
            status: 201,
        );
    }
}
