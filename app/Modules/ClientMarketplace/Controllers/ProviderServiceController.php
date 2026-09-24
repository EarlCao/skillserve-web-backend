<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientMarketplace\Requests\CommissionPreviewRequest;
use App\Modules\ClientMarketplace\Requests\ProviderServiceIndexRequest;
use App\Modules\ClientMarketplace\Requests\StoreProviderServiceRequest;
use App\Modules\ClientMarketplace\Requests\UpdateProviderServiceRequest;
use App\Modules\ClientMarketplace\Resources\ProviderServiceResource;
use App\Modules\ClientMarketplace\Services\ProviderServiceService;
use App\Modules\Commissions\Services\CommissionCalculator;
use App\Modules\Services\Models\Service;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Provider Services', description: 'Providers create and manage their own services; every submission and change awaits administrator approval')]
class ProviderServiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderServiceService $providerServiceService,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/provider/services',
        summary: 'List the authenticated provider\'s services (all approval states)',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'approval_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Provider services', content: new OA\JsonContent(ref: '#/components/schemas/ProviderServiceListEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified provider account required'),
            new OA\Response(response: 422, description: 'Invalid filters'),
        ],
    )]
    public function index(ProviderServiceIndexRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->providerServiceService->index($request->user(), $request->validated()),
            ProviderServiceResource::class,
            'Services retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/provider/services',
        summary: 'Submit a new service for administrator approval',
        description: 'Creates the service as draft/pending. It becomes visible to customers only after an administrator approves it. Amounts are in Philippine pesos (PHP). Requires a verified provider profile.',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            ref: '#/components/schemas/ProviderServiceInput',
            example: ['title' => 'Aircon Cleaning', 'description' => 'Split-type aircon deep cleaning.', 'category_id' => 1, 'subcategory_id' => null, 'price' => 1500, 'price_type' => 'fixed', 'duration' => '2 hours', 'location' => 'Quezon City'],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Service submitted (pending approval)', content: new OA\JsonContent(ref: '#/components/schemas/ProviderServiceEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a provider account, or provider not yet verified'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function store(StoreProviderServiceRequest $request): JsonResponse
    {
        return $this->success(
            new ProviderServiceResource($this->providerServiceService->create($request->user(), $request->validated())),
            'Service submitted for approval.',
            status: 201,
        );
    }

    #[OA\Get(
        path: '/api/client/v1/provider/services/{service}',
        summary: 'Get one of the provider\'s own services',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Service', content: new OA\JsonContent(ref: '#/components/schemas/ProviderServiceEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified provider account required'),
            new OA\Response(response: 404, description: 'Service not found or owned by another provider'),
        ],
    )]
    public function show(Request $request, Service $service): JsonResponse
    {
        return $this->success(
            new ProviderServiceResource($this->providerServiceService->show($request->user(), $service)),
            'Service retrieved.',
        );
    }

    #[OA\Put(
        path: '/api/client/v1/provider/services/{service}',
        summary: 'Update an owned service (returns it to pending approval)',
        description: 'Any actual change sets approval_status to pending and hides the service from customers until an administrator approves it again. Saving identical values changes nothing.',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProviderServiceInput')),
        responses: [
            new OA\Response(response: 200, description: 'Service updated', content: new OA\JsonContent(ref: '#/components/schemas/ProviderServiceEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a provider account, or provider not yet verified'),
            new OA\Response(response: 404, description: 'Service not found or owned by another provider'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function update(UpdateProviderServiceRequest $request, Service $service): JsonResponse
    {
        return $this->success(
            new ProviderServiceResource($this->providerServiceService->update($request->user(), $service, $request->validated())),
            'Service updated and sent for approval.',
        );
    }

    #[OA\Delete(
        path: '/api/client/v1/provider/services/{service}',
        summary: 'Delete an owned service',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Service deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified provider account required'),
            new OA\Response(response: 404, description: 'Service not found or owned by another provider'),
            new OA\Response(response: 409, description: 'Service has pending, confirmed or active bookings'),
        ],
    )]
    public function destroy(Request $request, Service $service): JsonResponse
    {
        $this->providerServiceService->delete($request->user(), $service);

        return $this->success(null, 'Service deleted.');
    }

    #[OA\Get(
        path: '/api/client/v1/provider/commission-preview',
        summary: 'What a provider would earn at a given price',
        description: "SkillServe's commission is included in the price the provider advertises: the customer pays `amount`, the platform takes `commission_amount` out of it, and the provider receives `net_amount`. Call this while the provider is choosing a price so the split is visible before publishing.\n\nIndicative only — the rate that binds a booking is snapshotted when the booking is made. `source` explains the rate: `tier` (a configured band matched), `gap` (the bands leave this amount uncovered, so nothing is charged) or `fallback` (no bands configured; the legacy flat rate applies).",
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'amount', in: 'query', required: true, description: 'The price being considered, in pesos', schema: new OA\Schema(type: 'number', format: 'float', minimum: 0, example: 200)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Commission breakdown', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Commission preview calculated.',
                    'data' => [
                        'amount' => '200.00',
                        'commission_rate' => '10.00',
                        'commission_amount' => '20.00',
                        'net_amount' => '180.00',
                        'currency' => 'PHP',
                        'tier_name' => 'Standard',
                        'source' => 'tier',
                    ],
                    'errors' => null,
                    'meta' => [],
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, email-verified provider account required'),
            new OA\Response(response: 422, description: 'Missing or invalid amount'),
        ],
    )]
    public function commissionPreview(CommissionPreviewRequest $request, CommissionCalculator $calculator): JsonResponse
    {
        $breakdown = $calculator->for((float) $request->validated('amount'));

        return $this->success([
            'amount' => number_format($breakdown['base_amount'], 2, '.', ''),
            'commission_rate' => number_format($breakdown['rate'], 2, '.', ''),
            'commission_amount' => number_format($breakdown['commission_amount'], 2, '.', ''),
            'net_amount' => number_format($breakdown['net_amount'], 2, '.', ''),
            'currency' => 'PHP',
            'tier_name' => $breakdown['tier_name'],
            'source' => $breakdown['source'],
        ], 'Commission preview calculated.');
    }
}
