<?php

namespace App\Modules\Commissions\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Commissions\Requests\UpdateCommissionSettingsRequest;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Commission Settings', description: 'The commission rules edited on the Commission page: the fallback rate and when unpaid commission blocks a provider. Stored with the marketplace settings but no longer shown in System Settings.')]
class CommissionSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingsService $settings) {}

    #[OA\Get(
        path: '/api/commission-settings',
        summary: 'Read the commission rules',
        tags: ['Commission Settings'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'commission_rate (fallback %, when no tier matches), commission_block_min_amount (₱, 0 = any debt), commission_block_after_days (0 = off)', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'view commissions required'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CommissionTier::class);

        return $this->success($this->settings->managedBy('commissions', 'marketplace'), 'Commission settings retrieved.');
    }

    #[OA\Put(
        path: '/api/commission-settings',
        summary: 'Change the commission rules',
        tags: ['Commission Settings'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'commission_rate', type: 'number', minimum: 0, maximum: 100, description: 'Fallback % when no commission tier matches a booking'),
            new OA\Property(property: 'commission_block_min_amount', type: 'number', minimum: 0, maximum: 1000000, description: 'Block once unpaid commission reaches this (₱); 0 = any amount'),
            new OA\Property(property: 'commission_block_after_days', type: 'integer', minimum: 0, maximum: 365, description: 'Also block once the oldest unpaid commission is this many days old; 0 = off'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'manage commissions required'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateCommissionSettingsRequest $request): JsonResponse
    {
        $this->authorize('create', CommissionTier::class);

        $this->settings->update(['marketplace' => $request->validated()], $request->user(), owner: 'commissions');

        return $this->success($this->settings->managedBy('commissions', 'marketplace'), 'Commission settings saved.');
    }
}
