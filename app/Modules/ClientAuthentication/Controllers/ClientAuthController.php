<?php

namespace App\Modules\ClientAuthentication\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ClientAuthentication\Requests\CancelClientRegistrationRequest;
use App\Modules\ClientAuthentication\Requests\ChangeClientPasswordRequest;
use App\Modules\ClientAuthentication\Requests\ClientLoginRequest;
use App\Modules\ClientAuthentication\Requests\ForgotClientPasswordRequest;
use App\Modules\ClientAuthentication\Requests\GoogleClientAuthRequest;
use App\Modules\ClientAuthentication\Requests\RefreshClientTokenRequest;
use App\Modules\ClientAuthentication\Requests\RegisterClientRequest;
use App\Modules\ClientAuthentication\Requests\RegisterProviderClientRequest;
use App\Modules\ClientAuthentication\Requests\ResendClientOtpRequest;
use App\Modules\ClientAuthentication\Requests\ResetClientPasswordRequest;
use App\Modules\ClientAuthentication\Requests\VerifyClientOtpRequest;
use App\Modules\ClientAuthentication\Resources\ClientAuthResource;
use App\Modules\ClientAuthentication\Resources\ClientUserResource;
use App\Modules\ClientAuthentication\Services\ClientAuthenticationService;
use App\Modules\ClientAuthentication\Services\ClientEmailOtpService;
use App\Modules\ClientAuthentication\Services\ClientGoogleAuthService;
use App\Modules\ClientAuthentication\Services\ClientProviderRegistrationService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Client Authentication', description: 'Customer registration, sessions and password management')]
class ClientAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ClientAuthenticationService $authenticationService,
        private readonly ClientProviderRegistrationService $providerRegistrationService,
        private readonly ClientEmailOtpService $otpService,
        private readonly ClientGoogleAuthService $googleAuthService,
    ) {}

    #[OA\Post(
        path: '/api/client/v1/auth/register',
        summary: 'Register a customer account',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['first_name', 'last_name', 'email', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'first_name', type: 'string', maxLength: 255, example: 'Alex'),
                new OA\Property(property: 'last_name', type: 'string', maxLength: 255, example: 'Customer'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'password123'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'password123'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Registered and logged in; email verification is required before marketplace access', content: new OA\JsonContent(ref: '#/components/schemas/ClientAuthEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function register(RegisterClientRequest $request): JsonResponse
    {
        return $this->success(
            new ClientAuthResource($this->authenticationService->register($request->validated())),
            'Registered successfully.',
            status: 201,
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/register-provider',
        summary: 'Register a service provider account from the mobile app',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['first_name', 'last_name', 'email', 'password', 'password_confirmation', 'specialization'],
            properties: [
                new OA\Property(property: 'first_name', type: 'string', maxLength: 255, example: 'Alex'),
                new OA\Property(property: 'last_name', type: 'string', maxLength: 255, example: 'Provider'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'provider@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'password123'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'password123'),
                new OA\Property(property: 'business_name', type: 'string', maxLength: 255, nullable: true, example: 'Alex Repairs'),
                new OA\Property(property: 'specialization', type: 'string', maxLength: 255, example: 'Home Repair'),
                new OA\Property(property: 'experience_years', type: 'integer', minimum: 0, example: 3),
                new OA\Property(property: 'bio', type: 'string', maxLength: 5000, nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Provider registered and logged in; account awaits administrator verification', content: new OA\JsonContent(ref: '#/components/schemas/ClientAuthEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function registerProvider(RegisterProviderClientRequest $request): JsonResponse
    {
        return $this->success(
            new ClientAuthResource($this->providerRegistrationService->register($request->validated())),
            'Provider account registered successfully. It is now pending verification.',
            status: 201,
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/verify-otp',
        summary: 'Verify a mobile account email with a 6-digit OTP',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'code'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
                new OA\Property(property: 'code', type: 'string', minLength: 6, maxLength: 6, example: '123456'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Email verified', content: new OA\JsonContent(ref: '#/components/schemas/ClientUserEnvelope')),
            new OA\Response(response: 422, description: 'Invalid or expired code', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 429, description: 'Too many attempts or resend cooldown', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function verifyOtp(VerifyClientOtpRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->validated('email'))
            ->where(fn ($q) => $q->where('user_type', 'customer')->orWhere('user_type', 'provider'))
            ->doesntHave('roles')
            ->first();

        if (! $user) {
            throw new ApiException('No account found for this email.', 404);
        }

        $verified = $this->otpService->verify($user, (string) $request->validated('code'));

        return $this->success(new ClientUserResource($verified), 'Email verified successfully.');
    }

    #[OA\Post(
        path: '/api/client/v1/auth/cancel-registration',
        summary: 'Delete an unverified account after the user backs out of email verification',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Unverified registration cancelled (always returned, even for unknown accounts, to prevent enumeration)', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function cancelRegistration(CancelClientRegistrationRequest $request): JsonResponse
    {
        // Verified and unknown accounts return the same response so the
        // endpoint cannot be used to probe which emails exist.
        $this->authenticationService->cancelUnverifiedRegistration($request->validated());

        return $this->success([], 'Registration cancelled.');
    }

    #[OA\Post(
        path: '/api/client/v1/auth/resend-otp',
        summary: 'Resend the mobile account verification OTP',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
            ],
        )),
        responses: [
            new OA\Response(response: 202, description: 'A new code has been sent', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 404, description: 'No account found', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 429, description: 'Resend cooldown active', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function resendOtp(ResendClientOtpRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');

        $user = User::query()
            ->where('email', $email)
            ->where(fn ($q) => $q->where('user_type', 'customer')->orWhere('user_type', 'provider'))
            ->doesntHave('roles')
            ->first();

        if (! $user) {
            throw new ApiException('No account found for this email.', 404);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->success([], 'Email is already verified.', status: 200);
        }

        try {
            $this->otpService->issue($user);
        } catch (\Throwable $e) {
            // Mail failures must be visible in the platform log stream
            // (the default channel writes inside the container where
            // Render cannot see them).
            Log::channel('stderr')->error('OTP send failed.', [
                'email' => $email,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $this->success([], 'A new verification code has been sent to your email.', status: 202);
    }

    #[OA\Post(
        path: '/api/client/v1/auth/google',
        summary: 'Sign in or sign up with a Google ID token (mobile)',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['id_token'],
            properties: [
                new OA\Property(property: 'id_token', type: 'string'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Authenticated with Google; account created on first sign-in', content: new OA\JsonContent(ref: '#/components/schemas/ClientAuthEnvelope')),
            new OA\Response(response: 401, description: 'Invalid Google token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Account not active', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function google(GoogleClientAuthRequest $request): JsonResponse
    {
        return $this->success(
            new ClientAuthResource($this->googleAuthService->authenticate($request->validated('id_token'))),
            'Logged in with Google successfully.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/login',
        summary: 'Login as a customer',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Logged in successfully', content: new OA\JsonContent(ref: '#/components/schemas/ClientAuthEnvelope')),
            new OA\Response(response: 401, description: 'Invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Inactive or unverified customer account', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function login(ClientLoginRequest $request): JsonResponse
    {
        return $this->success(
            new ClientAuthResource($this->authenticationService->login($request->validated())),
            'Logged in successfully.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/refresh',
        summary: 'Rotate a customer refresh token',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['refresh_token'],
            properties: [new OA\Property(property: 'refresh_token', type: 'string', minLength: 32)],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Tokens rotated', content: new OA\JsonContent(ref: '#/components/schemas/ClientAuthEnvelope')),
            new OA\Response(response: 401, description: 'Invalid, expired, or reused refresh token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Inactive or unverified customer account', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function refresh(RefreshClientTokenRequest $request): JsonResponse
    {
        return $this->success(
            new ClientAuthResource($this->authenticationService->rotate($request->validated()['refresh_token'])),
            'Token refreshed successfully.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/auth/me',
        summary: 'Get the current customer',
        tags: ['Client Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Authenticated customer', content: new OA\JsonContent(ref: '#/components/schemas/ClientUserEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Token is not a client token or account is inactive', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        return $this->success(new ClientUserResource($request->user()), 'Authenticated customer.');
    }

    #[OA\Get(
        path: '/api/client/v1/auth/verify-email/{user}/{hash}',
        summary: 'Verify a customer email address from its signed link',
        tags: ['Client Authentication'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'hash', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'expires', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Email verified', content: new OA\JsonContent(ref: '#/components/schemas/ClientUserEnvelope')),
            new OA\Response(response: 403, description: 'Invalid or expired verification link', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 404, description: 'Customer not found'),
        ],
    )]
    public function verifyEmail(Request $request, User $user): JsonResponse
    {
        return $this->success(
            new ClientUserResource($this->authenticationService->verifyEmail($user, (string) $request->route('hash'))),
            'Email verified successfully.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/verification-notification',
        summary: 'Resend the customer email verification link',
        tags: ['Client Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 202, description: 'Verification email sent', content: new OA\JsonContent(ref: '#/components/schemas/ClientUserEnvelope')),
            new OA\Response(response: 200, description: 'Email is already verified', content: new OA\JsonContent(ref: '#/components/schemas/ClientUserEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Inactive or non-client account', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function sendVerificationNotification(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authenticationService->sendVerificationNotification($user);

        return $this->success(
            new ClientUserResource($user->fresh()),
            $user->hasVerifiedEmail() ? 'Email is already verified.' : 'Verification email sent.',
            status: $user->hasVerifiedEmail() ? 200 : 202,
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/logout',
        summary: 'Logout and revoke customer sessions',
        tags: ['Client Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logged out successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Token is not a client token or account is inactive', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        $this->authenticationService->logout($request->user());

        return $this->success(null, 'Logged out successfully.');
    }

    #[OA\Post(
        path: '/api/client/v1/auth/change-password',
        summary: "Change the current customer's password",
        tags: ['Client Authentication'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password changed and sessions revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Token is not a client token or account is inactive', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error or wrong current password', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function changePassword(ChangeClientPasswordRequest $request): JsonResponse
    {
        $this->authenticationService->changePassword($request->user(), $request->validated());

        return $this->success(null, 'Password changed successfully.');
    }

    #[OA\Post(
        path: '/api/client/v1/auth/forgot-password',
        summary: 'Request a customer password reset email',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com')],
        )),
        responses: [
            new OA\Response(response: 202, description: 'Reset request accepted without account enumeration', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function forgotPassword(ForgotClientPasswordRequest $request): JsonResponse
    {
        $this->authenticationService->requestPasswordReset($request->validated()['email']);

        return $this->success(null, 'If the account exists, a password reset link has been sent.', status: 202);
    }

    #[OA\Post(
        path: '/api/client/v1/auth/reset-password',
        summary: 'Complete a customer password reset',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token', 'email', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password reset and sessions revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Invalid or expired reset token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function resetPassword(ResetClientPasswordRequest $request): JsonResponse
    {
        $this->authenticationService->resetPassword($request->validated());

        return $this->success(null, 'Password reset successfully.');
    }
}
