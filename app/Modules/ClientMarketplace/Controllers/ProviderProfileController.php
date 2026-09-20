<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientMarketplace\Requests\StorePortfolioItemRequest;
use App\Modules\ClientMarketplace\Requests\UpdateProviderAvailabilityRequest;
use App\Modules\ClientMarketplace\Requests\UpdateProviderProfileRequest;
use App\Modules\ClientMarketplace\Resources\ClientBadgeResource;
use App\Modules\ClientMarketplace\Resources\ClientPortfolioItemResource;
use App\Modules\ClientMarketplace\Resources\ProviderAvailabilityResource;
use App\Modules\ClientMarketplace\Resources\ProviderProfileResource;
use App\Modules\ClientMarketplace\Services\ProviderAccountService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Provider Services', description: 'Providers create and manage their own services; every submission and change awaits administrator approval')]
class ProviderProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderAccountService $accounts,
    ) {}

    /** The signed-in provider's own profile, or a 404 when they have none. */
    private function profileFor(Request $request): ProviderProfile
    {
        return $request->user()->providerProfile
            ?? throw new ApiException('Provider profile not found.', 404);
    }

    #[OA\Get(
        path: '/api/client/v1/provider/profile',
        summary: 'Get the authenticated provider\'s own profile (any verification state)',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Provider profile', content: new OA\JsonContent(ref: '#/components/schemas/ProviderProfileEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        return $this->success(
            new ProviderProfileResource($this->profileFor($request)),
            'Provider profile retrieved.',
        );
    }

    #[OA\Patch(
        path: '/api/client/v1/provider/profile',
        summary: "Update the authenticated provider's own professional profile",
        description: 'Partial update. Verification status, featured flag and rating counters are not editable here — they are set by administrators or earned.',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'business_name', type: 'string', maxLength: 255, nullable: true, example: 'Dela Cruz Home Services'),
                new OA\Property(property: 'bio', type: 'string', maxLength: 5000, nullable: true),
                new OA\Property(property: 'specialization', type: 'string', maxLength: 255, example: 'Plumbing'),
                new OA\Property(property: 'experience_years', type: 'integer', minimum: 0, maximum: 80, example: 5),
                new OA\Property(property: 'hourly_rate', type: 'number', format: 'float', nullable: true, example: 450),
                new OA\Property(property: 'location', type: 'string', maxLength: 255, nullable: true, example: 'Quezon City'),
                new OA\Property(property: 'website', type: 'string', format: 'uri', nullable: true),
                new OA\Property(property: 'skills', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                new OA\Property(property: 'certifications', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated profile', content: new OA\JsonContent(ref: '#/components/schemas/ProviderProfileEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function update(UpdateProviderProfileRequest $request): JsonResponse
    {
        return $this->success(
            new ProviderProfileResource(
                $this->accounts->updateProfile($this->profileFor($request), $request->validated()),
            ),
            'Provider profile updated.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/provider/availability',
        summary: "Get the authenticated provider's published weekly hours",
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Weekly schedule and booking availability', content: new OA\JsonContent(ref: '#/components/schemas/ProviderAvailabilityEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
        ],
    )]
    public function availability(Request $request): JsonResponse
    {
        $profile = $this->profileFor($request);

        return $this->success(
            $this->availabilityPayload($profile),
            'Availability retrieved.',
        );
    }

    #[OA\Put(
        path: '/api/client/v1/provider/availability',
        summary: "Replace the authenticated provider's weekly hours",
        description: 'Sending `availability` replaces the whole schedule; an empty array clears it, which means the provider publishes no hours rather than being unavailable. A provider with no published hours can be booked at any time.',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'is_accepting_bookings', type: 'boolean', example: true),
                new OA\Property(
                    property: 'availability',
                    type: 'array',
                    maxItems: 7,
                    description: 'At most one window per weekday',
                    items: new OA\Items(
                        required: ['day_of_week', 'start_time', 'end_time'],
                        properties: [
                            new OA\Property(property: 'day_of_week', type: 'integer', minimum: 0, maximum: 6, description: '0 = Sunday … 6 = Saturday', example: 1),
                            new OA\Property(property: 'start_time', type: 'string', example: '09:00'),
                            new OA\Property(property: 'end_time', type: 'string', example: '17:00'),
                        ],
                        type: 'object',
                    ),
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated schedule', content: new OA\JsonContent(ref: '#/components/schemas/ProviderAvailabilityEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function updateAvailability(UpdateProviderAvailabilityRequest $request): JsonResponse
    {
        $profile = $this->accounts->updateAvailability(
            $this->profileFor($request),
            $request->validated(),
        );

        return $this->success(
            $this->availabilityPayload($profile),
            'Availability updated.',
        );
    }

    /** The schedule always travels with the flag that gates booking. */
    private function availabilityPayload(ProviderProfile $profile): array
    {
        return [
            'is_accepting_bookings' => (bool) $profile->is_accepting_bookings,
            'availability' => ProviderAvailabilityResource::collection(
                $this->accounts->availability($profile),
            ),
        ];
    }

    #[OA\Get(
        path: '/api/client/v1/provider/portfolio',
        summary: "List the authenticated provider's own work samples",
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Portfolio items, newest first', content: new OA\JsonContent(ref: '#/components/schemas/ClientPortfolioListEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
        ],
    )]
    public function portfolio(Request $request): JsonResponse
    {
        return $this->success(
            ClientPortfolioItemResource::collection(
                $this->accounts->portfolio($this->profileFor($request)),
            ),
            'Portfolio retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/provider/portfolio',
        summary: 'Add a work sample to the provider\'s own portfolio',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['title', 'image'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Bathroom repipe'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true),
                    new OA\Property(property: 'image', type: 'string', format: 'binary', description: 'JPG, PNG or WebP, at most 5 MB'),
                ],
            ),
        )),
        responses: [
            new OA\Response(response: 201, description: 'Item added', content: new OA\JsonContent(ref: '#/components/schemas/ClientPortfolioEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function storePortfolioItem(StorePortfolioItemRequest $request): JsonResponse
    {
        $item = $this->accounts->addPortfolioItem(
            $this->profileFor($request),
            $request->file('image'),
            (string) $request->validated('title'),
            $request->validated('description'),
        );

        return $this->success(new ClientPortfolioItemResource($item), 'Portfolio item added.', status: 201);
    }

    #[OA\Delete(
        path: '/api/client/v1/provider/portfolio/{item}',
        summary: 'Remove one of the provider\'s own work samples',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Item not found, or it belongs to another provider'),
        ],
    )]
    public function destroyPortfolioItem(Request $request, int $item): JsonResponse
    {
        $this->accounts->removePortfolioItem($this->profileFor($request), $item);

        return $this->success(null, 'Portfolio item removed.');
    }

    #[OA\Get(
        path: '/api/client/v1/provider/badges',
        summary: "The authenticated provider's recognition badges",
        description: 'Returns the badges this provider has earned and the active badges still available, so the app can show progress toward the rest.',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Earned and available badges', content: new OA\JsonContent(ref: '#/components/schemas/ClientBadgeSetEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
        ],
    )]
    public function badges(Request $request): JsonResponse
    {
        $badges = $this->accounts->badges($this->profileFor($request));

        return $this->success([
            'earned' => ClientBadgeResource::collection($badges['earned']),
            'available' => ClientBadgeResource::collection($badges['available']),
        ], 'Badges retrieved.');
    }
}
