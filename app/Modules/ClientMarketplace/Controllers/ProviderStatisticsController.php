<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientMarketplace\Requests\ProviderStatisticsRequest;
use App\Modules\ClientMarketplace\Services\ProviderStatisticsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ProviderStatisticsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderStatisticsService $statistics,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/provider/statistics',
        summary: 'The signed-in provider\'s performance statistics',
        description: 'Computed from the provider\'s own bookings and reviews at request time. `monthly` holds one entry per calendar month in the business timezone (Asia/Manila), oldest first and ending with the current month: bookings completed (by completion date), bookings received (by creation date) and earnings (completed total price less SkillServe\'s fee, as on the Earnings screen). `summary.average_rating` is the provider\'s rating — the average of their services\' ratings; `rating_breakdown` counts published reviews per star. `completion_rate` is completed / (completed + cancelled) in percent, null before either happens.',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'months', in: 'query', description: 'Calendar months the chart covers, ending with this one (default 6)', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statistics', content: new OA\JsonContent(ref: '#/components/schemas/ProviderStatisticsEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
            new OA\Response(response: 404, description: 'Provider profile not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function show(ProviderStatisticsRequest $request): JsonResponse
    {
        $profile = $request->user()->providerProfile
            ?? throw new ApiException('Provider profile not found.', 404);

        return $this->success(
            $this->statistics->forProvider(
                $profile,
                (int) ($request->validated('months') ?? ProviderStatisticsService::DEFAULT_MONTHS),
            ),
            'Statistics retrieved.',
        );
    }
}
