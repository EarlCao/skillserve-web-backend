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

    #[OA\Get(path: '/api/settings', summary: 'Get system settings', tags: ['System Settings'], security: [['bearerAuth' => []]], responses: [new OA\Response(response: 200, description: 'Grouped system settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function index(): JsonResponse
    {
        abort_unless(request()->user()->can('manage settings'), 403);

        return $this->success($this->settingsService->all(), 'System settings retrieved.');
    }

    #[OA\Put(path: '/api/settings', summary: 'Update system settings', tags: ['System Settings'], security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', example: ['general' => ['platform_name' => 'SkillServe', 'support_email' => 'support@skillserve.test'], 'booking' => ['booking_enabled' => true]])), responses: [new OA\Response(response: 200, description: 'Settings updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')), new OA\Response(response: 422, description: 'Validation error'), new OA\Response(response: 403, description: 'Unauthorized')])]
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('manage settings'), 403);

        return $this->success($this->settingsService->update($request->validated(), $request->user()), 'System settings updated.');
    }
}
