<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Resources\DashboardResource;
use App\Modules\Dashboard\Services\DashboardService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Dashboard', description: 'Administrative dashboard summaries, activity, and analytics')]
class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DashboardService $dashboardService) {}

    #[OA\Get(
        path: '/api/dashboard',
        summary: 'Get dashboard summaries and analytics',
        description: 'commission_summary is included only when the viewer holds view commissions or manage commissions. Amounts are Philippine pesos; collected_rate is settled commission as a percentage of the settled bookings\' value.',
        tags: ['Dashboard'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard data', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Dashboard retrieved.',
                    'data' => [
                        'user_summary' => ['total_clients' => 0, 'total_providers' => 0, 'active_users' => 0, 'suspended_users' => 0],
                        'service_summary' => ['total_services' => 0, 'approved_services' => 0, 'pending_services' => 0, 'reported_services' => 0],
                        'booking_summary' => ['pending' => 0, 'confirmed' => 0, 'active' => 0, 'completed' => 0, 'cancelled' => 0, 'disputed' => 0],
                        'verification_summary' => ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'additional_info_required' => 0],
                        'reports_summary' => ['pending' => 0, 'investigating' => 0, 'resolved' => 0, 'rejected' => 0],
                        'recent_activities' => [],
                        'commission_summary' => [
                            'collected' => '12450.00',
                            'collected_booking_value' => '129700.00',
                            'collected_rate' => '9.60',
                            'outstanding' => '1200.00',
                            'waived' => '0.00',
                            'rate_source' => 'tiers',
                            'fallback_rate' => '10.00',
                            'tiers' => [['id' => 1, 'name' => 'Under ₱200', 'min_amount' => '0.00', 'max_amount' => '199.99', 'percentage' => '5.00']],
                        ],
                        'analytics' => ['monthly_activity' => [], 'booking_statuses' => [], 'user_statuses' => []],
                    ],
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Dashboard permission required'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('view dashboard');

        return $this->success(
            new DashboardResource($this->dashboardService->summary($request->user())),
            'Dashboard retrieved.',
        );
    }

    #[OA\Get(
        path: '/api/dashboard/attention',
        summary: 'Counts of work waiting for an administrator',
        description: 'For the sidebar badges: support tickets still `open` (nobody has replied) and reports still `pending` (nobody has picked them up). Needs no dashboard permission; each count is null when the viewer may not view that list. The admin web refetches it on every realtime change.',
        tags: ['Dashboard'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The counts', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Attention counts retrieved.',
                    'data' => ['open_support_tickets' => 3, 'pending_reports' => null],
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function attention(Request $request): JsonResponse
    {
        return $this->success($this->dashboardService->attention($request->user()), 'Attention counts retrieved.');
    }
}
