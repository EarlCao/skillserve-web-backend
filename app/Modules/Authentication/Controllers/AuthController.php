<?php

namespace App\Modules\Authentication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Requests\ChangePasswordRequest;
use App\Modules\Authentication\Requests\LoginRequest;
use App\Modules\Authentication\Resources\UserResource;
use App\Modules\Authentication\Services\AuthenticationService;
use App\Modules\Authentication\Services\PasswordService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Authentication endpoints. Controllers stay thin — all business logic lives
 * in the AuthenticationService / PasswordService / Actions.
 *
 * OpenAPI is documented with PHP 8 attributes (l5-swagger v11 default
 * analyser does not read @OA docblocks).
 */
#[OA\Tag(name: 'Authentication', description: 'Admin login, session and password management')]
class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuthenticationService $authenticationService,
        private readonly PasswordService $passwordService,
    ) {
    }

    /**
     * POST /api/auth/login — validate credentials and issue a Sanctum token.
     */
    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Login and get a bearer token',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@skillserve.test'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'SkillServe#2026'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Logged in successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 429, description: 'Too many login attempts', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        return $this->success(
            $this->authenticationService->login($request->validated()),
            'Logged in successfully.',
        );
    }

    /**
     * POST /api/auth/logout — revoke the current session token.
     */
    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Logout and revoke the current token',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logged out successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        $this->authenticationService->logout($request->user());

        return $this->success(null, 'Logged out successfully.');
    }

    /**
     * GET /api/auth/me — the authenticated administrator (roles + permissions).
     */
    #[OA\Get(
        path: '/api/auth/me',
        summary: 'Get the current user',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Authenticated user', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()), 'Authenticated user.');
    }

    /**
     * POST /api/auth/change-password — verify the current password and store a new one.
     */
    #[OA\Post(
        path: '/api/auth/change-password',
        summary: "Change the current user's password",
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['current_password', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password changed successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated / expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error / wrong current password', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->passwordService->changePassword($request->user(), $request->validated());

        return $this->success(null, 'Password changed successfully.');
    }
}
