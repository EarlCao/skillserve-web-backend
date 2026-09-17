<?php

namespace App\Modules\ClientMarketplace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientMarketplace\Resources\ProviderProfileResource;
use App\Shared\Exceptions\ApiException;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Provider Services', description: 'Providers create and manage their own services; every submission and change awaits administrator approval')]
class ProviderProfileController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/client/v1/provider/profile',
        summary: 'Get the authenticated provider\'s own profile (any verification state)',
        tags: ['Provider Services'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Provider profile', content: new OA\JsonContent(ref: '#/components/schemas/ProviderProfileEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active, verified-email provider account required'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->providerProfile
            ?? throw new ApiException('Provider profile not found.', 404);

        return $this->success(new ProviderProfileResource($profile), 'Provider profile retrieved.');
    }
}
