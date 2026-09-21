<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * What the mobile app needs from System Settings: platform identity, the
 * published policies (M 14.4, A 17.5), the booking rules it explains to users,
 * and whether the platform is in maintenance.
 */
#[OA\Tag(name: 'Client Platform', description: 'Public platform information, policies and rules for the mobile app')]
class PlatformController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingsService $settings) {}

    #[OA\Get(
        path: '/api/client/v1/platform',
        summary: 'Platform information, policies and rules',
        description: 'Public. Stays available during maintenance mode, so the app can tell when the platform is back. Policy texts are the ones administrators write in System Settings → Platform policies (empty until written).',
        tags: ['Client Platform'],
        responses: [
            new OA\Response(response: 200, description: 'Platform information', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Platform information retrieved.',
                    'data' => [
                        'platform_name' => 'SkillServe',
                        'platform_description' => '',
                        'support_email' => 'support@skillserve.ph',
                        'maintenance_mode' => false,
                        'provider_registration_enabled' => true,
                        'booking' => ['booking_enabled' => true, 'cancellation_window_hours' => 24, 'client_cancellation_fee_percent' => 0, 'provider_cancellation_fee_percent' => 0],
                        'policies' => ['terms_of_service' => '…', 'privacy_policy' => '…', 'community_guidelines' => '…'],
                    ],
                    'errors' => null,
                    'meta' => [],
                ],
            )),
        ],
    )]
    public function show(): JsonResponse
    {
        $value = fn (string $group, string $name) => $this->settings->value($group, $name);

        return $this->success([
            'platform_name' => (string) $value('general', 'platform_name'),
            'platform_description' => (string) $value('general', 'platform_description'),
            'support_email' => (string) $value('general', 'support_email'),
            'maintenance_mode' => (bool) $value('system', 'maintenance_mode'),
            'provider_registration_enabled' => (bool) $value('marketplace', 'provider_registration_enabled'),
            'booking' => [
                'booking_enabled' => (bool) $value('booking', 'booking_enabled'),
                'cancellation_window_hours' => (int) $value('booking', 'cancellation_window_hours'),
                'client_cancellation_fee_percent' => (float) $value('booking', 'client_cancellation_fee_percent'),
                'provider_cancellation_fee_percent' => (float) $value('booking', 'provider_cancellation_fee_percent'),
            ],
            'policies' => [
                'terms_of_service' => (string) $value('policies', 'terms_of_service'),
                'privacy_policy' => (string) $value('policies', 'privacy_policy'),
                'community_guidelines' => (string) $value('policies', 'community_guidelines'),
            ],
        ], 'Platform information retrieved.');
    }
}
