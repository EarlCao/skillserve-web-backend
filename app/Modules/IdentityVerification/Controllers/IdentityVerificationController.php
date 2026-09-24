<?php

namespace App\Modules\IdentityVerification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\IdentityVerification\Models\IdentityDocument;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\IdentityVerification\Requests\ApproveIdentityVerificationRequest;
use App\Modules\IdentityVerification\Requests\IdentityVerificationIndexRequest;
use App\Modules\IdentityVerification\Requests\RejectIdentityVerificationRequest;
use App\Modules\IdentityVerification\Resources\AdminIdentityVerificationResource;
use App\Modules\IdentityVerification\Services\IdentityVerificationService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The National ID review queue.
 *
 * Reviewing means handling government identity documents, so the permissions
 * are narrow and separate: "view identity verifications" to read,
 * "verify identities" to approve, "reject identities" to refuse. The card
 * number is never returned by any of these endpoints — the reviewer confirms
 * it by opening the ID image through the authorised download below.
 */
#[OA\Tag(name: 'Identity Verifications', description: 'Review Philippine National ID submissions from customers and providers')]
class IdentityVerificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly IdentityVerificationService $verification,
    ) {}

    #[OA\Get(
        path: '/api/identity-verifications',
        summary: 'List National ID submissions',
        description: "Filterable review queue. Searching matches the account's name or email — the card number is deliberately not searchable, because hashing a search term would turn this endpoint into a \"does SkillServe know this ID?\" oracle.",
        tags: ['Identity Verifications'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: IdentityVerificationIndexRequest::STATUSES)),
            new OA\Parameter(name: 'account_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['customer', 'provider'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Account name or email', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['submitted_at', 'reviewed_at', 'created_at'], default: 'submitted_at')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated submissions', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the view identity verifications permission'),
            new OA\Response(response: 422, description: 'Invalid filter values'),
        ],
    )]
    public function index(IdentityVerificationIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', IdentityVerification::class);

        return $this->paginated(
            $this->verification->index($request->validated()),
            AdminIdentityVerificationResource::class,
            'Identity verifications retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/identity-verifications/{identityVerification}',
        summary: 'Show one submission with its documents and history',
        tags: ['Identity Verifications'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'identityVerification', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Submission detail', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the view identity verifications permission'),
            new OA\Response(response: 404, description: 'Submission not found'),
        ],
    )]
    public function show(IdentityVerification $identityVerification): JsonResponse
    {
        $this->authorize('view', $identityVerification);

        return $this->success(
            new AdminIdentityVerificationResource($this->verification->showForAdmin($identityVerification)),
            'Identity verification retrieved.',
        );
    }

    #[OA\Patch(
        path: '/api/identity-verifications/{identityVerification}/approve',
        summary: 'Confirm the National ID belongs to the account holder',
        description: 'Only a pending submission can be decided; a second decision returns 409. Approving starts the retention clock on the stored images (System Settings → Identity).',
        tags: ['Identity Verifications'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'identityVerification', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true, description: 'Internal note; never shown to the account holder')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Identity verified', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the verify identities permission'),
            new OA\Response(response: 404, description: 'Submission not found'),
            new OA\Response(response: 409, description: 'Already reviewed'),
        ],
    )]
    public function approve(ApproveIdentityVerificationRequest $request, IdentityVerification $identityVerification): JsonResponse
    {
        $this->authorize('verify', $identityVerification);

        return $this->success(
            new AdminIdentityVerificationResource($this->verification->approve(
                $identityVerification,
                $request->user(),
                $request->validated('notes'),
            )),
            'Identity verified.',
        );
    }

    #[OA\Patch(
        path: '/api/identity-verifications/{identityVerification}/reject',
        summary: 'Refuse a National ID submission',
        description: 'A reason is required and is shown to the account holder so they can correct the problem and resubmit. Only a pending submission can be decided.',
        tags: ['Identity Verifications'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'identityVerification', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000)],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Submission rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the reject identities permission'),
            new OA\Response(response: 404, description: 'Submission not found'),
            new OA\Response(response: 409, description: 'Already reviewed'),
            new OA\Response(response: 422, description: 'A reason is required'),
        ],
    )]
    public function reject(RejectIdentityVerificationRequest $request, IdentityVerification $identityVerification): JsonResponse
    {
        $this->authorize('reject', $identityVerification);

        return $this->success(
            new AdminIdentityVerificationResource($this->verification->reject(
                $identityVerification,
                $request->user(),
                $request->validated('reason'),
            )),
            'Identity verification rejected.',
        );
    }

    #[OA\Get(
        path: '/api/identity-verifications/{identityVerification}/documents/{document}/download',
        summary: 'Open a National ID image',
        description: 'Streams the file from the private disk. The image is never reachable by URL, the document must belong to the submission in the path, and every request is authorised against the view permission.',
        tags: ['Identity Verifications'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'identityVerification', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The file'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the view identity verifications permission'),
            new OA\Response(response: 404, description: 'Not found, not part of this submission, or already purged'),
        ],
    )]
    public function downloadDocument(IdentityVerification $identityVerification, IdentityDocument $document): StreamedResponse
    {
        $this->authorize('view', $identityVerification);

        // The document must belong to the submission named in the path, or a
        // reviewer could walk other people's IDs by changing the id.
        abort_unless($document->identity_verification_id === $identityVerification->id, 404);

        $disk = Storage::disk(config('identity.disk'));
        abort_unless($disk->exists($document->file_path), 404);

        activity('identity_verifications')
            ->causedBy(request()->user())
            ->performedOn($identityVerification)
            ->withProperties(['document_id' => $document->id, 'document_type' => $document->document_type])
            ->log('identity_document_opened');

        return $disk->download($document->file_path, $document->file_name, [
            'Content-Type' => $document->file_mime_type,
        ]);
    }
}
