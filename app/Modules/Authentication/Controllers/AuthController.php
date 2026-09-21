<?php

namespace App\Modules\Authentication\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Requests\ChangePasswordRequest;
use App\Modules\Authentication\Requests\ForgotPasswordRequest;
use App\Modules\Authentication\Requests\LoginRequest;
use App\Modules\Authentication\Requests\ResetPasswordRequest;
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
    ) {}

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
                example: [
                    'email' => 'admin@skillserve.test',
                    'password' => 'SkillServe#2026',
                ],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@skillserve.test'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'SkillServe#2026'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logged in successfully',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Logged in successfully.',
                        'data' => [
                            'token' => '1|a1b2c3d4e5f6g7h8i9j0',
                            'token_type' => 'Bearer',
                            'expires_at' => '2026-08-08T15:00:00+00:00',
                            'user' => [
                                'id' => 1,
                                'name' => 'System Administrator',
                                'email' => 'admin@skillserve.test',
                                'roles' => ['super-admin'],
                                'permissions' => ['manage administrators'],
                                'created_at' => '2026-08-07T15:00:00+00:00',
                            ],
                        ],
                        'errors' => null,
                        'meta' => null,
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid credentials',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Invalid email or password.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'email' => ['The email field must be a valid email address.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 429,
                description: 'Too many login attempts',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Too many login attempts. Please try again later.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
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
            new OA\Response(
                response: 200,
                description: 'Logged out successfully',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Logged out successfully.',
                        'data' => null,
                        'errors' => null,
                        'meta' => null,
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
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
            new OA\Response(
                response: 200,
                description: 'Authenticated user',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Authenticated user.',
                        'data' => [
                            'id' => 1,
                            'name' => 'System Administrator',
                            'email' => 'admin@skillserve.test',
                            'roles' => ['super-admin'],
                            'permissions' => ['manage administrators'],
                            'created_at' => '2026-08-07T15:00:00+00:00',
                        ],
                        'errors' => null,
                        'meta' => null,
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
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
                example: [
                    'current_password' => 'SkillServe#2026',
                    'password' => 'SkillServe#2027',
                    'password_confirmation' => 'SkillServe#2027',
                ],
                properties: [
                    new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Password changed successfully',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => true,
                        'message' => 'Password changed successfully.',
                        'data' => null,
                        'errors' => null,
                        'meta' => null,
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated / expired token',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'Unauthenticated.',
                        'data' => new \stdClass,
                        'errors' => null,
                        'meta' => [],
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error / wrong current password',
                content: new OA\JsonContent(
                    ref: '#/components/schemas/ApiEnvelope',
                    example: [
                        'success' => false,
                        'message' => 'The given data was invalid.',
                        'data' => new \stdClass,
                        'errors' => [
                            'current_password' => ['The current password is incorrect.'],
                        ],
                        'meta' => [],
                    ],
                ),
            ),
        ],
    )]
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->passwordService->changePassword($request->user(), $request->validated());

        return $this->success(null, 'Password changed successfully.');
    }

    #[OA\Post(
        path: '/api/auth/forgot-password',
        summary: 'Email an administrator a password reset link',
        description: 'Always answers the same way, whether or not the address belongs to an active administrator, so accounts cannot be discovered. The link opens the admin web reset page and expires in 60 minutes. Rate-limited like login.',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email'], properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@skillserve.test'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Reset link sent if the account exists', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ],
    )]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordService->requestReset($request->validated('email'));

        return $this->success(null, 'If that email belongs to an administrator, a reset link is on its way.');
    }

    #[OA\Post(
        path: '/api/auth/reset-password',
        summary: 'Reset an administrator password with an emailed token',
        description: 'Sets the new password and signs the administrator out everywhere.',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token', 'email', 'password', 'password_confirmation'], properties: [
            new OA\Property(property: 'token', type: 'string'),
            new OA\Property(property: 'email', type: 'string', format: 'email'),
            new OA\Property(property: 'password', type: 'string', minLength: 8, format: 'password'),
            new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Password reset', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Invalid or expired token, or validation error'),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ],
    )]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordService->resetPassword($request->validated());

        return $this->success(null, 'Password reset. Sign in with your new password.');
    }
}
