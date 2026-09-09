<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Requests\UpdateSettingsRequest;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'System Settings', description: 'Manage general, marketplace, booking, notification, policy, and technical platform settings')]
class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingsService $settingsService) {}

    #[OA\Get(path: '/api/settings', summary: 'Get system settings', tags: ['System Settings'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Grouped system settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function index(): JsonResponse
    {
        abort_unless(request()->user()->can('manage settings'), 403);

        return $this->success($this->settingsService->all(), 'System settings retrieved.');
    }

    #[OA\Put(
        path: '/api/settings',
        summary: 'Update system settings',
        description: 'Updates only known, unlocked settings. The system.session_timeout_minutes value controls new administrator token expiry and is enforced for existing administrator bearer tokens.',
        tags: ['System Settings'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                example: ['general' => ['platform_name' => 'SkillServe', 'support_email' => 'support@skillserve.test'], 'booking' => ['booking_enabled' => true], 'system' => ['session_timeout_minutes' => 1440]],
                properties: [
                    new OA\Property(property: 'general', type: 'object', additionalProperties: false, properties: [
                        new OA\Property(property: 'platform_name', type: 'string', maxLength: 120),
                        new OA\Property(property: 'platform_description', type: 'string', nullable: true, maxLength: 1000),
                        new OA\Property(property: 'support_email', type: 'string', format: 'email', nullable: true),
                        new OA\Property(property: 'timezone', type: 'string'),
                    ]),
                    new OA\Property(property: 'marketplace', type: 'object', additionalProperties: false, properties: [
                        new OA\Property(property: 'provider_registration_enabled', type: 'boolean'),
                        new OA\Property(property: 'service_approval_required', type: 'boolean'),
                        new OA\Property(property: 'featured_services_enabled', type: 'boolean'),
                        new OA\Property(property: 'commission_rate', type: 'number', minimum: 0, maximum: 100),
                    ]),
                    new OA\Property(property: 'booking', type: 'object', additionalProperties: false, properties: [
                        new OA\Property(property: 'booking_enabled', type: 'boolean'),
                        new OA\Property(property: 'cancellation_window_hours', type: 'integer', minimum: 0, maximum: 720),
                        new OA\Property(property: 'client_cancellation_fee_percent', type: 'number', minimum: 0, maximum: 100),
                        new OA\Property(property: 'provider_cancellation_fee_percent', type: 'number', minimum: 0, maximum: 100),
                    ]),
                    new OA\Property(property: 'notifications', type: 'object', additionalProperties: false, properties: [
                        new OA\Property(property: 'email_notifications_enabled', type: 'boolean'),
                        new OA\Property(property: 'push_notifications_enabled', type: 'boolean'),
                        new OA\Property(property: 'announcement_notifications_enabled', type: 'boolean'),
                    ]),
                    new OA\Property(property: 'policies', type: 'object', additionalProperties: false, properties: [
                        new OA\Property(property: 'terms_of_service', type: 'string', nullable: true),
                        new OA\Property(property: 'privacy_policy', type: 'string', nullable: true),
                        new OA\Property(property: 'community_guidelines', type: 'string', nullable: true),
                    ]),
                    new OA\Property(property: 'system', type: 'object', additionalProperties: false, properties: [
                        new OA\Property(property: 'maintenance_mode', type: 'boolean'),
                        new OA\Property(property: 'session_timeout_minutes', type: 'integer', minimum: 5, maximum: 43200),
                        new OA\Property(property: 'default_page_size', type: 'integer', minimum: 1, maximum: 100),
                    ]),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Settings updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('manage settings'), 403);

        return $this->success($this->settingsService->update($request->validated(), $request->user()), 'System settings updated.');
    }
}
