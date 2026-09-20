<?php

namespace App\Modules\ClientPreferences\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientPreferences\Requests\UpdateClientPreferencesRequest;
use App\Modules\ClientPreferences\Resources\ClientPreferenceResource;
use App\Modules\ClientPreferences\Services\ClientPreferenceService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Client Preferences', description: 'Notification, privacy and application settings for a mobile account')]
class ClientPreferenceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ClientPreferenceService $preferences,
    ) {}

    #[OA\Get(
        path: '/api/client/v1/preferences',
        summary: "Get the signed-in account's settings",
        description: 'Returns the stored settings, creating them with the platform defaults on first call. Settings are account-scoped, so they follow the user to any device.',
        tags: ['Client Preferences'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Current settings', content: new OA\JsonContent(ref: '#/components/schemas/ClientPreferenceEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Token is not a client token or account is inactive', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        return $this->success(
            new ClientPreferenceResource($this->preferences->forUser($request->user())),
            'Preferences retrieved.',
        );
    }

    #[OA\Put(
        path: '/api/client/v1/preferences',
        summary: "Update the signed-in account's settings",
        description: <<<'TXT'
            Partial update: send only the settings that changed.

            These are enforced server-side, not just shown in the app:
             * the four notification flags gate whether that category is delivered at all;
             * `private_profile` removes a provider from public discovery and hides their services from the catalogue.
            TXT,
        tags: ['Client Preferences'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'booking_notifications', type: 'boolean', example: true),
                new OA\Property(property: 'service_notifications', type: 'boolean', example: true),
                new OA\Property(property: 'message_notifications', type: 'boolean', example: true),
                new OA\Property(property: 'announcement_notifications', type: 'boolean', example: true),
                new OA\Property(property: 'private_profile', type: 'boolean', example: false),
                new OA\Property(property: 'activity_personalization', type: 'boolean', example: true),
                new OA\Property(property: 'reduce_motion', type: 'boolean', example: false),
                new OA\Property(property: 'theme', type: 'string', enum: ['light', 'dark', 'system'], example: 'system'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated settings', content: new OA\JsonContent(ref: '#/components/schemas/ClientPreferenceEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Token is not a client token or account is inactive', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function update(UpdateClientPreferencesRequest $request): JsonResponse
    {
        return $this->success(
            new ClientPreferenceResource($this->preferences->update($request->user(), $request->validated())),
            'Preferences updated.',
        );
    }
}
