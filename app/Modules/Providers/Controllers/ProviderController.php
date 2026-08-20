<?php

namespace App\Modules\Providers\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Requests\ApproveVerificationRequest;
use App\Modules\Providers\Requests\RejectVerificationRequest;
use App\Modules\Providers\Requests\RequestAdditionalInfoRequest;
use App\Modules\Providers\Requests\SuspendProviderRequest;
use App\Modules\Providers\Resources\ProviderResource;
use App\Modules\Providers\Services\ProviderService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Provider management endpoints — listing, profile viewing, verification
 * review, approval, rejection, additional info requests, suspension,
 * activation, and verification removal.
 *
 * Every action is authorization-gated through the "manage providers" gate.
 */
#[OA\Tag(name: 'Providers', description: 'Manage service provider accounts and verification workflows')]
class ProviderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderService $providerService,
    ) {}

    /**
     * GET /api/providers — paginated, searchable, filterable list of providers.
     */
    #[OA\Get(
        path: '/api/providers',
        summary: 'List service providers',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by business name, user name, or email', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by account status', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'suspended'])),
            new OA\Parameter(name: 'verification', in: 'query', description: 'Filter by verification status', required: false, schema: new OA\Schema(type: 'string', enum: ['unverified', 'pending', 'verified', 'rejected', 'additional_info_required'])),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort column', required: false, schema: new OA\Schema(type: 'string', enum: ['created_at', 'average_rating', 'total_bookings', 'business_name'], default: 'created_at')),
            new OA\Parameter(name: 'direction', in: 'query', description: 'Sort direction', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of providers',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
            ),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProviderProfile::class);

        $paginator = $this->providerService->index($request->only([
            'search', 'status', 'verification', 'sort', 'direction', 'per_page',
        ]));

        return $this->paginated($paginator, ProviderResource::class, 'Providers retrieved.');
    }

    /**
     * GET /api/providers/{provider} — detailed provider profile.
     */
    #[OA\Get(
        path: '/api/providers/{provider}',
        summary: 'Get a provider profile',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Provider profile details',
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
            ),
            new OA\Response(
                response: 404,
                description: 'Provider not found',
            ),
        ],
    )]
    public function show(ProviderProfile $provider): JsonResponse
    {
        $this->authorize('view', $provider);

        $provider = $this->providerService->show($provider);

        return $this->success(new ProviderResource($provider));
    }

    /**
     * PATCH /api/providers/{provider}/verification/approve — approve verification.
     */
    #[OA\Patch(
        path: '/api/providers/{provider}/verification/approve',
        summary: 'Approve provider verification',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                example: ['notes' => 'All documents verified successfully.'],
                properties: [
                    new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true, example: 'All documents verified successfully.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Provider verification approved',
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Provider not found'),
            new OA\Response(response: 422, description: 'Validation error or invalid state'),
        ],
    )]
    public function approveVerification(ApproveVerificationRequest $request, ProviderProfile $provider): JsonResponse
    {
        $this->authorize('verify', $provider);

        $verificationRequest = $provider->latestVerificationRequest;

        if (! $verificationRequest) {
            return $this->error('No pending verification request found.', 404);
        }

        $result = $this->providerService->approveVerification(
            $verificationRequest,
            $request->user(),
            $request->validated('notes'),
        );

        return $this->success(
            new ProviderResource($provider->fresh(['user', 'verifiedBy'])),
            'Provider verification approved.',
        );
    }

    /**
     * PATCH /api/providers/{provider}/verification/reject — reject verification.
     */
    #[OA\Patch(
        path: '/api/providers/{provider}/verification/reject',
        summary: 'Reject provider verification',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                example: ['reason' => 'Documents are unclear and illegible.'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 1000, example: 'Documents are unclear and illegible.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Provider verification rejected',
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Provider not found'),
            new OA\Response(response: 422, description: 'Validation error or invalid state'),
        ],
    )]
    public function rejectVerification(RejectVerificationRequest $request, ProviderProfile $provider): JsonResponse
    {
        $this->authorize('reject', $provider);

        $verificationRequest = $provider->latestVerificationRequest;

        if (! $verificationRequest) {
            return $this->error('No pending verification request found.', 404);
        }

        $result = $this->providerService->rejectVerification(
            $verificationRequest,
            $request->user(),
            $request->validated('reason'),
        );

        return $this->success(
            new ProviderResource($provider->fresh(['user'])),
            'Provider verification rejected.',
        );
    }

    /**
     * PATCH /api/providers/{provider}/verification/request-info — request additional info.
     */
    #[OA\Patch(
        path: '/api/providers/{provider}/verification/request-info',
        summary: 'Request additional information from provider',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                example: ['message' => 'Please provide a clearer copy of your government ID.'],
                properties: [
                    new OA\Property(property: 'message', type: 'string', maxLength: 2000, example: 'Please provide a clearer copy of your government ID.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Additional information requested',
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Provider not found'),
            new OA\Response(response: 422, description: 'Validation error or invalid state'),
        ],
    )]
    public function requestAdditionalInfo(RequestAdditionalInfoRequest $request, ProviderProfile $provider): JsonResponse
    {
        $this->authorize('requestAdditionalInfo', $provider);

        $verificationRequest = $provider->latestVerificationRequest;

        if (! $verificationRequest) {
            return $this->error('No pending verification request found.', 404);
        }

        $result = $this->providerService->requestAdditionalInfo(
            $verificationRequest,
            $request->user(),
            $request->validated('message'),
        );

        return $this->success(
            new ProviderResource($provider->fresh(['user'])),
            'Additional information requested.',
        );
    }

    /**
     * PATCH /api/providers/{provider}/suspend — suspend a provider.
     */
    #[OA\Patch(
        path: '/api/providers/{provider}/suspend',
        summary: 'Suspend a provider',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                example: ['reason' => 'Violation of platform terms.'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Violation of platform terms.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Provider suspended',
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Provider not found'),
            new OA\Response(response: 422, description: 'Validation error or already suspended'),
        ],
    )]
    public function suspend(SuspendProviderRequest $request, ProviderProfile $provider): JsonResponse
    {
        $this->authorize('suspend', $provider);

        $provider = $this->providerService->suspend(
            $provider,
            $request->user(),
            $request->validated('reason'),
        );

        return $this->success(new ProviderResource($provider), 'Provider suspended.');
    }

    /**
     * PATCH /api/providers/{provider}/activate — activate a suspended provider.
     */
    #[OA\Patch(
        path: '/api/providers/{provider}/activate',
        summary: 'Activate a suspended provider',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Provider activated',
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Provider not found'),
            new OA\Response(response: 422, description: 'Not suspended'),
        ],
    )]
    public function activate(Request $request, ProviderProfile $provider): JsonResponse
    {
        $this->authorize('activate', $provider);

        $provider = $this->providerService->activate(
            $provider,
            $request->user(),
        );

        return $this->success(new ProviderResource($provider), 'Provider activated.');
    }

    /**
     * PATCH /api/providers/{provider}/verification/remove — remove verified status.
     */
    #[OA\Patch(
        path: '/api/providers/{provider}/verification/remove',
        summary: 'Remove provider verification',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Provider verification removed',
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Provider not found'),
            new OA\Response(response: 422, description: 'Not verified'),
        ],
    )]
    public function removeVerification(Request $request, ProviderProfile $provider): JsonResponse
    {
        $this->authorize('removeVerification', $provider);

        $provider = $this->providerService->removeVerification(
            $provider,
            $request->user(),
        );

        return $this->success(new ProviderResource($provider), 'Provider verification removed.');
    }

    /**
     * GET /api/providers/{provider}/verification-history — verification request history.
     */
    #[OA\Get(
        path: '/api/providers/{provider}/verification-history',
        summary: 'Get provider verification history',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Verification history (newest first)',
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Provider not found'),
        ],
    )]
    public function verificationHistory(ProviderProfile $provider): JsonResponse
    {
        $this->authorize('view', $provider);

        return $this->success(
            $this->providerService->verificationHistory($provider),
            'Verification history retrieved.',
        );
    }
}
