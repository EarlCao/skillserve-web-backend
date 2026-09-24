<?php

namespace App\Modules\Commissions\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Commissions\Requests\CommissionTierIndexRequest;
use App\Modules\Commissions\Requests\StoreCommissionTierRequest;
use App\Modules\Commissions\Requests\UpdateCommissionTierRequest;
use App\Modules\Commissions\Resources\CommissionTierResource;
use App\Modules\Commissions\Services\CommissionTierService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Commission tier configuration — the bands that decide what percentage of a
 * booking SkillServe takes.
 *
 * Reading needs "view commissions"; every change needs "manage commissions"
 * (super administrators bypass via Gate::before). See CommissionTierPolicy.
 */
#[OA\Tag(name: 'Commission Tiers', description: 'Configure the commission percentage charged for each booking amount range')]
class CommissionTierController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CommissionTierService $tiers,
    ) {}

    #[OA\Get(
        path: '/api/commission-tiers',
        summary: 'List commission tiers',
        description: 'Ordered by minimum amount so the bands read as a ladder. Amounts are Philippine pesos; both range bounds are inclusive and a null max_amount is the open-ended top band.',
        tags: ['Commission Tiers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search by tier name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, description: 'Filter by enabled state', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: CommissionTierIndexRequest::SORTABLE, default: 'min_amount')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated commission tiers', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Commission tiers retrieved.',
                    'data' => [[
                        'id' => 2,
                        'name' => 'Standard',
                        'min_amount' => '200.00',
                        'max_amount' => '499.99',
                        'is_open_ended' => false,
                        'percentage' => '10.00',
                        'is_active' => true,
                        'created_at' => '2026-09-24T08:00:00+00:00',
                        'updated_at' => '2026-09-24T08:00:00+00:00',
                    ]],
                    'errors' => null,
                    'meta' => ['pagination' => ['total' => 1, 'per_page' => 15, 'current_page' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1]],
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the view commissions permission'),
            new OA\Response(response: 422, description: 'Invalid filter values'),
        ],
    )]
    public function index(CommissionTierIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CommissionTier::class);

        return $this->paginated(
            $this->tiers->index($request->validated()),
            CommissionTierResource::class,
            'Commission tiers retrieved.',
        );
    }

    #[OA\Post(
        path: '/api/commission-tiers',
        summary: 'Create a commission tier',
        description: 'Both bounds are inclusive. Omit max_amount (or send null) to create the open-ended top band. The range may not overlap another enabled tier.',
        tags: ['Commission Tiers'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'min_amount', 'percentage'],
            properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Standard'),
                new OA\Property(property: 'min_amount', type: 'number', format: 'float', minimum: 0, example: 200),
                new OA\Property(property: 'max_amount', type: 'number', format: 'float', nullable: true, example: 499.99, description: 'Inclusive upper bound; null means open ended'),
                new OA\Property(property: 'percentage', type: 'number', format: 'float', minimum: 0, maximum: 100, example: 10),
                new OA\Property(property: 'is_active', type: 'boolean', default: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Tier created', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the manage commissions permission'),
            new OA\Response(response: 422, description: 'Validation failed, or the range overlaps an enabled tier'),
        ],
    )]
    public function store(StoreCommissionTierRequest $request): JsonResponse
    {
        $this->authorize('create', CommissionTier::class);

        return $this->success(
            new CommissionTierResource($this->tiers->store($request->validated(), $request->user())),
            'Commission tier created.',
            status: 201,
        );
    }

    #[OA\Get(
        path: '/api/commission-tiers/{commissionTier}',
        summary: 'Show a commission tier',
        tags: ['Commission Tiers'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'commissionTier', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Commission tier', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the view commissions permission'),
            new OA\Response(response: 404, description: 'Tier not found'),
        ],
    )]
    public function show(CommissionTier $commissionTier): JsonResponse
    {
        $this->authorize('view', $commissionTier);

        return $this->success(
            new CommissionTierResource($this->tiers->show($commissionTier)),
            'Commission tier retrieved.',
        );
    }

    #[OA\Put(
        path: '/api/commission-tiers/{commissionTier}',
        summary: 'Update a commission tier',
        description: 'Partial update. Disabling a tier (is_active=false) lifts the overlap rule for it, because a disabled band charges nobody. Bookings already charged under this tier keep their own rate snapshot and are unaffected.',
        tags: ['Commission Tiers'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'commissionTier', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 120),
                new OA\Property(property: 'min_amount', type: 'number', format: 'float', minimum: 0),
                new OA\Property(property: 'max_amount', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'percentage', type: 'number', format: 'float', minimum: 0, maximum: 100),
                new OA\Property(property: 'is_active', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Tier updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the manage commissions permission'),
            new OA\Response(response: 404, description: 'Tier not found'),
            new OA\Response(response: 422, description: 'Validation failed, or the range overlaps an enabled tier'),
        ],
    )]
    public function update(UpdateCommissionTierRequest $request, CommissionTier $commissionTier): JsonResponse
    {
        $this->authorize('update', $commissionTier);

        return $this->success(
            new CommissionTierResource($this->tiers->update($commissionTier, $request->validated(), $request->user())),
            'Commission tier updated.',
        );
    }

    #[OA\Delete(
        path: '/api/commission-tiers/{commissionTier}',
        summary: 'Retire a commission tier',
        description: 'Soft deletion. Bookings charged under this tier keep their rate snapshot, so past commissions never change.',
        tags: ['Commission Tiers'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'commissionTier', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Tier retired'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing the manage commissions permission'),
            new OA\Response(response: 404, description: 'Tier not found'),
        ],
    )]
    public function destroy(Request $request, CommissionTier $commissionTier): JsonResponse
    {
        $this->authorize('delete', $commissionTier);

        $this->tiers->destroy($commissionTier, $request->user());

        return $this->noContent();
    }
}
