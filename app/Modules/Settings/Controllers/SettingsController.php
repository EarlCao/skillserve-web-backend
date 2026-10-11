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

    #[OA\Get(
        path: '/api/appearance',
        summary: 'The admin web theme',
        description: 'Public, so the sign-in page is themed too. `primary_color` is a #RRGGBB hex, or empty for the built-in SkillServe green; `font_family` is one of system, inter, poppins, roboto, outfit, nunito, open_sans, montserrat; `font_size` is small, medium, large or extra_large. Set in System Settings → Appearance.',
        tags: ['System Settings'],
        responses: [
            new OA\Response(response: 200, description: 'The theme', content: new OA\JsonContent(
                ref: '#/components/schemas/ApiEnvelope',
                example: [
                    'success' => true,
                    'message' => 'Appearance retrieved.',
                    'data' => ['primary_color' => '#03833E', 'font_family' => 'poppins', 'font_size' => 'medium'],
                    'errors' => null,
                    'meta' => [],
                ],
            )),
        ],
    )]
    public function appearance(): JsonResponse
    {
        $value = fn (string $name): string => (string) $this->settingsService->value('appearance', $name);

        return $this->success([
            'primary_color' => $value('primary_color'),
            'font_family' => $value('font_family'),
            'font_size' => $value('font_size'),
        ], 'Appearance retrieved.');
    }

    #[OA\Get(path: '/api/settings', summary: 'Get system settings', description: 'meta.read_only lists "group.name" settings shown for information only (for example general.timezone, which follows the server APP_TIMEZONE).', tags: ['System Settings'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Grouped system settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function index(): JsonResponse
    {
        abort_unless(request()->user()->can('manage settings'), 403);

        return $this->success(
            $this->settingsService->all(),
            'System settings retrieved.',
            meta: ['read_only' => $this->settingsService->readOnly()],
        );
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
                        new OA\Property(property: 'timezone', type: 'string', readOnly: true, description: 'Read-only; follows APP_TIMEZONE. Sending it returns 422.'),
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

        return $this->success(
            $this->settingsService->update($request->validated(), $request->user()),
            'System settings updated.',
            meta: ['read_only' => $this->settingsService->readOnly()],
        );
    }
}
