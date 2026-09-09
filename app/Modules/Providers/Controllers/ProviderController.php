<?php

namespace App\Modules\Providers\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationDocument;
use App\Modules\Providers\Requests\ApproveVerificationRequest;
use App\Modules\Providers\Requests\RejectVerificationRequest;
use App\Modules\Providers\Requests\RequestAdditionalInfoRequest;
use App\Modules\Providers\Requests\SuspendProviderRequest;
use App\Modules\Providers\Resources\ProviderResource;
use App\Modules\Providers\Services\ProviderService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                    example: [
                        'success' => true,
                        'message' => 'Providers retrieved.',
                        'data' => [
                            [
                                'id' => 1,
                                'user_id' => 2,
                                'user' => [
                                    'id' => 2,
                                    'name' => 'John Smith',
                                    'email' => 'john@example.com',
                                    'phone' => '+1234567890',
                                    'created_at' => '2026-08-01T08:00:00+00:00',
                                ],
                                'business_name' => 'Smith Plumbing Co.',
                                'bio' => 'Experienced plumber with 10 years in residential and commercial work.',
                                'specialization' => 'Plumbing',
                                'experience_years' => 10,
                                'hourly_rate' => '65.00',
                                'location' => 'New York, NY',
                                'website' => 'https://smithplumbing.example.com',
                                'social_links' => ['linkedin' => 'https://linkedin.com/in/johnsmith'],
                                'portfolio' => [],
                                'skills' => ['Pipe Repair', 'Water Heater Installation', 'Drain Cleaning'],
                                'certifications' => ['Licensed Plumber - NY'],
                                'languages' => ['English', 'Spanish'],
                                'average_rating' => '4.80',
                                'total_reviews' => 45,
                                'total_bookings' => 120,
                                'completed_bookings' => 115,
                                'verification_status' => 'verified',
                                'verified_at' => '2026-08-05T10:00:00+00:00',
                                'verified_by' => ['id' => 1, 'name' => 'Admin User'],
                                'rejection_reason' => null,
                                'is_suspended' => false,
                                'suspended_at' => null,
                                'suspended_by' => null,
                                'suspension_reason' => null,
                                'latest_verification_request' => null,
                                'created_at' => '2026-08-01T08:00:00+00:00',
                                'updated_at' => '2026-08-12T09:00:00+00:00',
                            ],
                        ],
                        'errors' => null,
                        'meta' => ['pagination' => ['total' => 1, 'per_page' => 15, 'current_page' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1]],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
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
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Request successful.',
                        'data' => [
                            'id' => 1,
                            'user_id' => 2,
                            'user' => [
                                'id' => 2,
                                'name' => 'John Smith',
                                'email' => 'john@example.com',
                                'phone' => '+1234567890',
                                'created_at' => '2026-08-01T08:00:00+00:00',
                            ],
                            'business_name' => 'Smith Plumbing Co.',
                            'bio' => 'Experienced plumber with 10 years in residential and commercial work.',
                            'specialization' => 'Plumbing',
                            'experience_years' => 10,
                            'hourly_rate' => '65.00',
                            'location' => 'New York, NY',
                            'website' => 'https://smithplumbing.example.com',
                            'social_links' => ['linkedin' => 'https://linkedin.com/in/johnsmith'],
                            'portfolio' => [],
                            'skills' => ['Pipe Repair', 'Water Heater Installation', 'Drain Cleaning'],
                            'certifications' => ['Licensed Plumber - NY'],
                            'languages' => ['English', 'Spanish'],
                            'average_rating' => '4.80',
                            'total_reviews' => 45,
                            'total_bookings' => 120,
                            'completed_bookings' => 115,
                            'verification_status' => 'verified',
                            'verified_at' => '2026-08-05T10:00:00+00:00',
                            'verified_by' => ['id' => 1, 'name' => 'Admin User'],
                            'rejection_reason' => null,
                            'is_suspended' => false,
                            'suspended_at' => null,
                            'suspended_by' => null,
                            'suspension_reason' => null,
                            'latest_verification_request' => [
                                'id' => 5,
                                'provider_profile_id' => 1,
                                'status' => 'approved',
                                'notes' => null,
                                'admin_notes' => null,
                                'rejection_reason' => null,
                                'additional_info_request' => null,
                                'submitted_at' => '2026-08-04T09:00:00+00:00',
                                'reviewed_at' => '2026-08-05T10:00:00+00:00',
                                'reviewed_by' => ['id' => 1, 'name' => 'Admin User'],
                                'documents' => [
                                    [
                                        'id' => 10,
                                        'verification_request_id' => 5,
                                        'document_type' => 'government_id',
                                        'file_name' => 'drivers_license.pdf',
                                        'file_mime_type' => 'application/pdf',
                                        'file_size' => 1048576,
                                        'formatted_file_size' => '1 MB',
                                        'description' => 'Government-issued driver license',
                                        'created_at' => '2026-08-04T09:00:00+00:00',
                                    ],
                                ],
                                'documents_count' => 1,
                                'created_at' => '2026-08-04T09:00:00+00:00',
                                'updated_at' => '2026-08-05T10:00:00+00:00',
                            ],
                            'created_at' => '2026-08-01T08:00:00+00:00',
                            'updated_at' => '2026-08-12T09:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Provider not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
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
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Provider verification approved.',
                        'data' => [
                            'id' => 1,
                            'user_id' => 2,
                            'user' => [
                                'id' => 2,
                                'name' => 'John Smith',
                                'email' => 'john@example.com',
                                'phone' => '+1234567890',
                                'created_at' => '2026-08-01T08:00:00+00:00',
                            ],
                            'business_name' => 'Smith Plumbing Co.',
                            'bio' => 'Experienced plumber with 10 years in residential and commercial work.',
                            'specialization' => 'Plumbing',
                            'experience_years' => 10,
                            'hourly_rate' => '65.00',
                            'location' => 'New York, NY',
                            'website' => 'https://smithplumbing.example.com',
                            'social_links' => ['linkedin' => 'https://linkedin.com/in/johnsmith'],
                            'portfolio' => [],
                            'skills' => ['Pipe Repair', 'Water Heater Installation', 'Drain Cleaning'],
                            'certifications' => ['Licensed Plumber - NY'],
                            'languages' => ['English', 'Spanish'],
                            'average_rating' => '4.80',
                            'total_reviews' => 45,
                            'total_bookings' => 120,
                            'completed_bookings' => 115,
                            'verification_status' => 'verified',
                            'verified_at' => '2026-08-12T10:00:00+00:00',
                            'verified_by' => ['id' => 1, 'name' => 'Admin User'],
                            'rejection_reason' => null,
                            'is_suspended' => false,
                            'suspended_at' => null,
                            'suspended_by' => null,
                            'suspension_reason' => null,
                            'latest_verification_request' => null,
                            'created_at' => '2026-08-01T08:00:00+00:00',
                            'updated_at' => '2026-08-12T10:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Provider not found or no pending verification request',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'No pending verification request found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error or invalid state',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'notes' => ['The notes field must not exceed 1000 characters.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
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
            new ProviderResource($this->providerService->show($provider)),
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
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Provider verification rejected.',
                        'data' => [
                            'id' => 1,
                            'user_id' => 2,
                            'user' => [
                                'id' => 2,
                                'name' => 'John Smith',
                                'email' => 'john@example.com',
                                'phone' => '+1234567890',
                                'created_at' => '2026-08-01T08:00:00+00:00',
                            ],
                            'business_name' => 'Smith Plumbing Co.',
                            'bio' => 'Experienced plumber with 10 years in residential and commercial work.',
                            'specialization' => 'Plumbing',
                            'experience_years' => 10,
                            'hourly_rate' => '65.00',
                            'location' => 'New York, NY',
                            'website' => 'https://smithplumbing.example.com',
                            'social_links' => ['linkedin' => 'https://linkedin.com/in/johnsmith'],
                            'portfolio' => [],
                            'skills' => ['Pipe Repair', 'Water Heater Installation', 'Drain Cleaning'],
                            'certifications' => ['Licensed Plumber - NY'],
                            'languages' => ['English', 'Spanish'],
                            'average_rating' => '4.80',
                            'total_reviews' => 45,
                            'total_bookings' => 120,
                            'completed_bookings' => 115,
                            'verification_status' => 'rejected',
                            'verified_at' => null,
                            'verified_by' => null,
                            'rejection_reason' => 'Documents are unclear and illegible.',
                            'is_suspended' => false,
                            'suspended_at' => null,
                            'suspended_by' => null,
                            'suspension_reason' => null,
                            'latest_verification_request' => null,
                            'created_at' => '2026-08-01T08:00:00+00:00',
                            'updated_at' => '2026-08-12T10:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Provider not found or no pending verification request',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'No pending verification request found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error or invalid state',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'reason' => ['The reason field is required.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
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
            new ProviderResource($this->providerService->show($provider)),
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
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Additional information requested.',
                        'data' => [
                            'id' => 1,
                            'user_id' => 2,
                            'user' => [
                                'id' => 2,
                                'name' => 'John Smith',
                                'email' => 'john@example.com',
                                'phone' => '+1234567890',
                                'created_at' => '2026-08-01T08:00:00+00:00',
                            ],
                            'business_name' => 'Smith Plumbing Co.',
                            'bio' => 'Experienced plumber with 10 years in residential and commercial work.',
                            'specialization' => 'Plumbing',
                            'experience_years' => 10,
                            'hourly_rate' => '65.00',
                            'location' => 'New York, NY',
                            'website' => 'https://smithplumbing.example.com',
                            'social_links' => ['linkedin' => 'https://linkedin.com/in/johnsmith'],
                            'portfolio' => [],
                            'skills' => ['Pipe Repair', 'Water Heater Installation', 'Drain Cleaning'],
                            'certifications' => ['Licensed Plumber - NY'],
                            'languages' => ['English', 'Spanish'],
                            'average_rating' => '4.80',
                            'total_reviews' => 45,
                            'total_bookings' => 120,
                            'completed_bookings' => 115,
                            'verification_status' => 'additional_info_required',
                            'verified_at' => null,
                            'verified_by' => null,
                            'rejection_reason' => null,
                            'is_suspended' => false,
                            'suspended_at' => null,
                            'suspended_by' => null,
                            'suspension_reason' => null,
                            'latest_verification_request' => null,
                            'created_at' => '2026-08-01T08:00:00+00:00',
                            'updated_at' => '2026-08-12T10:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Provider not found or no pending verification request',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'No pending verification request found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error or invalid state',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'message' => ['The message field is required.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
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
            new ProviderResource($this->providerService->show($provider)),
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
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Provider suspended.',
                        'data' => [
                            'id' => 1,
                            'user_id' => 2,
                            'user' => [
                                'id' => 2,
                                'name' => 'John Smith',
                                'email' => 'john@example.com',
                                'phone' => '+1234567890',
                                'created_at' => '2026-08-01T08:00:00+00:00',
                            ],
                            'business_name' => 'Smith Plumbing Co.',
                            'bio' => 'Experienced plumber with 10 years in residential and commercial work.',
                            'specialization' => 'Plumbing',
                            'experience_years' => 10,
                            'hourly_rate' => '65.00',
                            'location' => 'New York, NY',
                            'website' => 'https://smithplumbing.example.com',
                            'social_links' => ['linkedin' => 'https://linkedin.com/in/johnsmith'],
                            'portfolio' => [],
                            'skills' => ['Pipe Repair', 'Water Heater Installation', 'Drain Cleaning'],
                            'certifications' => ['Licensed Plumber - NY'],
                            'languages' => ['English', 'Spanish'],
                            'average_rating' => '4.80',
                            'total_reviews' => 45,
                            'total_bookings' => 120,
                            'completed_bookings' => 115,
                            'verification_status' => 'verified',
                            'verified_at' => '2026-08-05T10:00:00+00:00',
                            'verified_by' => ['id' => 1, 'name' => 'Admin User'],
                            'rejection_reason' => null,
                            'is_suspended' => true,
                            'suspended_at' => '2026-08-12T10:00:00+00:00',
                            'suspended_by' => ['id' => 1, 'name' => 'Admin User'],
                            'suspension_reason' => 'Violation of platform terms.',
                            'latest_verification_request' => null,
                            'created_at' => '2026-08-01T08:00:00+00:00',
                            'updated_at' => '2026-08-12T10:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Provider not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error or already suspended',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'reason' => ['The reason field is required.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
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

        return $this->success(new ProviderResource($this->providerService->show($provider)), 'Provider suspended.');
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
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Provider activated.',
                        'data' => [
                            'id' => 1,
                            'user_id' => 2,
                            'user' => [
                                'id' => 2,
                                'name' => 'John Smith',
                                'email' => 'john@example.com',
                                'phone' => '+1234567890',
                                'created_at' => '2026-08-01T08:00:00+00:00',
                            ],
                            'business_name' => 'Smith Plumbing Co.',
                            'bio' => 'Experienced plumber with 10 years in residential and commercial work.',
                            'specialization' => 'Plumbing',
                            'experience_years' => 10,
                            'hourly_rate' => '65.00',
                            'location' => 'New York, NY',
                            'website' => 'https://smithplumbing.example.com',
                            'social_links' => ['linkedin' => 'https://linkedin.com/in/johnsmith'],
                            'portfolio' => [],
                            'skills' => ['Pipe Repair', 'Water Heater Installation', 'Drain Cleaning'],
                            'certifications' => ['Licensed Plumber - NY'],
                            'languages' => ['English', 'Spanish'],
                            'average_rating' => '4.80',
                            'total_reviews' => 45,
                            'total_bookings' => 120,
                            'completed_bookings' => 115,
                            'verification_status' => 'verified',
                            'verified_at' => '2026-08-05T10:00:00+00:00',
                            'verified_by' => ['id' => 1, 'name' => 'Admin User'],
                            'rejection_reason' => null,
                            'is_suspended' => false,
                            'suspended_at' => null,
                            'suspended_by' => null,
                            'suspension_reason' => null,
                            'latest_verification_request' => null,
                            'created_at' => '2026-08-01T08:00:00+00:00',
                            'updated_at' => '2026-08-12T10:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Provider not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Not currently suspended',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Provider is not currently suspended.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function activate(Request $request, ProviderProfile $provider): JsonResponse
    {
        $this->authorize('activate', $provider);

        $provider = $this->providerService->activate(
            $provider,
            $request->user(),
        );

        return $this->success(new ProviderResource($this->providerService->show($provider)), 'Provider activated.');
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
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Provider verification removed.',
                        'data' => [
                            'id' => 1,
                            'user_id' => 2,
                            'user' => [
                                'id' => 2,
                                'name' => 'John Smith',
                                'email' => 'john@example.com',
                                'phone' => '+1234567890',
                                'created_at' => '2026-08-01T08:00:00+00:00',
                            ],
                            'business_name' => 'Smith Plumbing Co.',
                            'bio' => 'Experienced plumber with 10 years in residential and commercial work.',
                            'specialization' => 'Plumbing',
                            'experience_years' => 10,
                            'hourly_rate' => '65.00',
                            'location' => 'New York, NY',
                            'website' => 'https://smithplumbing.example.com',
                            'social_links' => ['linkedin' => 'https://linkedin.com/in/johnsmith'],
                            'portfolio' => [],
                            'skills' => ['Pipe Repair', 'Water Heater Installation', 'Drain Cleaning'],
                            'certifications' => ['Licensed Plumber - NY'],
                            'languages' => ['English', 'Spanish'],
                            'average_rating' => '4.80',
                            'total_reviews' => 45,
                            'total_bookings' => 120,
                            'completed_bookings' => 115,
                            'verification_status' => 'unverified',
                            'verified_at' => null,
                            'verified_by' => null,
                            'rejection_reason' => null,
                            'is_suspended' => false,
                            'suspended_at' => null,
                            'suspended_by' => null,
                            'suspension_reason' => null,
                            'latest_verification_request' => null,
                            'created_at' => '2026-08-01T08:00:00+00:00',
                            'updated_at' => '2026-08-12T10:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Provider not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Not currently verified',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Provider is not currently verified.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function removeVerification(Request $request, ProviderProfile $provider): JsonResponse
    {
        $this->authorize('removeVerification', $provider);

        $provider = $this->providerService->removeVerification(
            $provider,
            $request->user(),
        );

        return $this->success(new ProviderResource($this->providerService->show($provider)), 'Provider verification removed.');
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
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Verification history retrieved.',
                        'data' => [
                            [
                                'id' => 5,
                                'provider_profile_id' => 1,
                                'status' => 'approved',
                                'notes' => null,
                                'admin_notes' => 'All documents verified.',
                                'rejection_reason' => null,
                                'additional_info_request' => null,
                                'submitted_at' => '2026-08-04T09:00:00+00:00',
                                'reviewed_at' => '2026-08-05T10:00:00+00:00',
                                'reviewed_by' => ['id' => 1, 'name' => 'Admin User'],
                                'documents' => [
                                    [
                                        'id' => 10,
                                        'verification_request_id' => 5,
                                        'document_type' => 'government_id',
                                        'file_name' => 'drivers_license.pdf',
                                        'file_mime_type' => 'application/pdf',
                                        'file_size' => 1048576,
                                        'formatted_file_size' => '1 MB',
                                        'description' => 'Government-issued driver license',
                                        'created_at' => '2026-08-04T09:00:00+00:00',
                                    ],
                                ],
                                'documents_count' => 1,
                                'created_at' => '2026-08-04T09:00:00+00:00',
                                'updated_at' => '2026-08-05T10:00:00+00:00',
                            ],
                            [
                                'id' => 3,
                                'provider_profile_id' => 1,
                                'status' => 'rejected',
                                'notes' => null,
                                'admin_notes' => null,
                                'rejection_reason' => 'Documents are unclear and illegible.',
                                'additional_info_request' => null,
                                'submitted_at' => '2026-07-15T09:00:00+00:00',
                                'reviewed_at' => '2026-07-16T14:00:00+00:00',
                                'reviewed_by' => ['id' => 1, 'name' => 'Admin User'],
                                'documents' => [
                                    [
                                        'id' => 7,
                                        'verification_request_id' => 3,
                                        'document_type' => 'government_id',
                                        'file_name' => 'blurry_id.jpg',
                                        'file_mime_type' => 'image/jpeg',
                                        'file_size' => 524288,
                                        'formatted_file_size' => '512 KB',
                                        'description' => 'Blurred government ID photo',
                                        'created_at' => '2026-07-15T09:00:00+00:00',
                                    ],
                                ],
                                'documents_count' => 1,
                                'created_at' => '2026-07-15T09:00:00+00:00',
                                'updated_at' => '2026-07-16T14:00:00+00:00',
                            ],
                        ],
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Missing the manage providers permission',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'This action is unauthorized.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Provider not found',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Resource not found.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    #[OA\Get(
        path: '/api/providers/{provider}/verification-documents/{document}/download',
        summary: 'Download a provider verification document',
        description: 'Downloads a verification document only after checking the provider scope and the view-providers permission. The file is served from private storage.',
        tags: ['Providers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'document', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Private verification document download'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Provider, document, or private file not found'),
        ],
    )]
    public function downloadVerificationDocument(ProviderProfile $provider, VerificationDocument $document): StreamedResponse
    {
        $this->authorize('view', $provider);

        $document->loadMissing('verificationRequest');
        abort_unless($document->verificationRequest?->provider_profile_id === $provider->id, 404);

        $disk = Storage::disk('verification');
        abort_unless($disk->exists($document->file_path), 404);

        return $disk->download($document->file_path, $document->file_name, [
            'Content-Type' => $document->file_mime_type,
        ]);
    }

    public function verificationHistory(ProviderProfile $provider): JsonResponse
    {
        $this->authorize('view', $provider);

        return $this->success(
            $this->providerService->verificationHistory($provider),
            'Verification history retrieved.',
        );
    }
}
