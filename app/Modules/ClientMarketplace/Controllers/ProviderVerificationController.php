<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientMarketplace\Requests\SubmitProviderVerificationRequest;
use App\Modules\ClientMarketplace\Resources\ProviderVerificationResource;
use App\Modules\ClientMarketplace\Services\ProviderVerificationService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Provider Verification', description: 'The signed-in provider submits verification documents and follows the review')]
class ProviderVerificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderVerificationService $verification,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/provider/verification',
        summary: "The provider's verification status, latest request and documents",
        description: 'verification_status is unverified, pending, verified, rejected or additional_info_required. can_submit tells the app whether to offer an upload. The request carries the rejection reason or the information an administrator asked for.',
        tags: ['Provider Verification'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Verification state', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        return $this->success(
            new ProviderVerificationResource($this->verification->show($this->profileFor($request))),
            'Verification status retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/provider/verification',
        summary: 'Submit verification documents for review',
        description: 'Allowed while unverified, rejected (starts a new request) or additional_info_required (adds documents to the same request — the answer to an information request). Moves the provider to pending review. Files are private: JPG, PNG or PDF, up to 10 MB each, at most 5 per submission.',
        tags: ['Provider Verification'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['documents'],
                properties: [
                    new OA\Property(property: 'documents[0][type]', type: 'string', enum: SubmitProviderVerificationRequest::DOCUMENT_TYPES),
                    new OA\Property(property: 'documents[0][file]', type: 'string', format: 'binary'),
                    new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true, description: 'Optional message to the reviewer'),
                ],
            ),
        )),
        responses: [
            new OA\Response(response: 201, description: 'Submitted; status is now pending', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
            new OA\Response(response: 422, description: 'Invalid files, or already pending / verified'),
        ],
    )]
    public function store(SubmitProviderVerificationRequest $request): JsonResponse
    {
        $documents = collect($request->validated('documents'))
            ->map(fn (array $document, int $index): array => [
                'type' => $document['type'],
                'file' => $request->file("documents.{$index}.file"),
            ])
            ->values()
            ->all();

        return $this->success(
            new ProviderVerificationResource($this->verification->submit(
                $this->profileFor($request),
                $request->user(),
                $documents,
                $request->validated('notes'),
            )),
            'Documents submitted for review.',
            status: 201,
        );
    }

    private function profileFor(Request $request): ProviderProfile
    {
        return $request->user()->providerProfile
            ?? throw new ApiException('Provider profile not found.', 404);
    }
}
