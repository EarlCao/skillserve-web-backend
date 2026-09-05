<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Resources\DashboardResource;
use App\Modules\Dashboard\Services\DashboardService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Dashboard', description: 'Administrative dashboard summaries, activity, and analytics')]
class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DashboardService $dashboardService) {}

    #[OA\Get(
        path: '/api/dashboard',
        summary: 'Get dashboard summaries and analytics',
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
                        'analytics' => ['monthly_activity' => [], 'booking_statuses' => [], 'user_statuses' => []],
                    ],
                ],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Dashboard permission required'),
        ],
    )]
    public function index(): JsonResponse
    {
        $this->authorize('view dashboard');

        return $this->success(
            new DashboardResource($this->dashboardService->summary()),
            'Dashboard retrieved.',
        );
    }
}
