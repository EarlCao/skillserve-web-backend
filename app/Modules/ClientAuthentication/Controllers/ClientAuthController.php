<?php

namespace App\Modules\ClientAuthentication\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ClientAuthentication\Requests\CancelClientRegistrationRequest;
use App\Modules\ClientAuthentication\Requests\ChangeClientPasswordRequest;
use App\Modules\ClientAuthentication\Requests\ClientLoginRequest;
use App\Modules\ClientAuthentication\Requests\CompleteGoogleRegistrationRequest;
use App\Modules\ClientAuthentication\Requests\DeleteClientAccountRequest;
use App\Modules\ClientAuthentication\Requests\ForgotClientPasswordRequest;
use App\Modules\ClientAuthentication\Requests\GoogleClientAuthRequest;
use App\Modules\ClientAuthentication\Requests\RefreshClientTokenRequest;
use App\Modules\ClientAuthentication\Requests\RegisterClientRequest;
use App\Modules\ClientAuthentication\Requests\RegisterProviderClientRequest;
use App\Modules\ClientAuthentication\Requests\ResendClientOtpRequest;
use App\Modules\ClientAuthentication\Requests\ResetClientPasswordRequest;
use App\Modules\ClientAuthentication\Requests\UpdateClientProfilePhotoRequest;
use App\Modules\ClientAuthentication\Requests\UpdateClientProfileRequest;
use App\Modules\ClientAuthentication\Requests\VerifyClientOtpRequest;
use App\Modules\ClientAuthentication\Resources\ClientAuthResource;
use App\Modules\ClientAuthentication\Resources\ClientGoogleAuthResource;
use App\Modules\ClientAuthentication\Resources\ClientUserResource;
use App\Modules\ClientAuthentication\Resources\PendingRegistrationResource;
use App\Modules\ClientAuthentication\Services\AccountDataService;
use App\Modules\ClientAuthentication\Services\ClientAuthenticationService;
use App\Modules\ClientAuthentication\Services\ClientEmailOtpService;
use App\Modules\ClientAuthentication\Services\ClientGoogleAuthService;
use App\Modules\ClientAuthentication\Services\ClientProfileService;
use App\Modules\ClientAuthentication\Services\ClientProviderRegistrationService;
use App\Modules\ClientAuthentication\Services\PendingRegistrationService;
use App\Shared\Exceptions\AccountRestrictedException;
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
        private readonly PendingRegistrationService $pendingRegistrations,
        private readonly ClientProfileService $profileService,
        private readonly AccountDataService $accountDataService,
    ) {}

    #[OA\Post(
        path: '/api/client/v1/auth/register',
        summary: 'Start a customer sign-up (no account until the email is verified)',
        description: 'Parks the sign-up and emails a 6-digit code. The `users` row is created by POST /auth/verify-otp, so abandoning verification leaves the address free to register again.',
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
            new OA\Response(response: 202, description: 'Verification code sent; confirm it to create the account', content: new OA\JsonContent(ref: '#/components/schemas/ClientPendingRegistrationEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 429, description: 'A code was sent moments ago; wait before retrying', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 503, description: 'The verification email could not be sent', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function register(RegisterClientRequest $request): JsonResponse
    {
        return $this->success(
            new PendingRegistrationResource($this->authenticationService->register($request->validated())),
            'Verification code sent. Enter it to finish creating your account.',
            status: 202,
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/register-provider',
        summary: 'Start a service provider sign-up from the mobile app',
        description: 'Same deferred flow as customer registration: the provider account and its pending `provider_profiles` row are created by POST /auth/verify-otp.',
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
            new OA\Response(response: 202, description: 'Verification code sent; confirm it to create the provider account', content: new OA\JsonContent(ref: '#/components/schemas/ClientPendingRegistrationEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 429, description: 'A code was sent moments ago; wait before retrying', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 503, description: 'The verification email could not be sent', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function registerProvider(RegisterProviderClientRequest $request): JsonResponse
    {
        return $this->success(
            new PendingRegistrationResource($this->providerRegistrationService->register($request->validated())),
            'Verification code sent. Enter it to finish creating your provider account.',
            status: 202,
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/verify-otp',
        summary: 'Confirm the 6-digit code, creating the account and signing in',
        description: 'For a sign-up started by /auth/register or /auth/register-provider this creates the account and returns a session. Accounts registered before sign-ups were deferred are simply marked verified and signed in.',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'code'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
                new OA\Property(property: 'code', type: 'string', minLength: 6, maxLength: 6, example: '123456'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Email verified, account created and signed in', content: new OA\JsonContent(ref: '#/components/schemas/ClientAuthEnvelope')),
            new OA\Response(response: 403, description: 'Account is not active', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 404, description: 'No sign-up or account found for this email', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 409, description: 'The email was registered by someone else while this code was outstanding', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Invalid or expired code', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 429, description: 'Too many incorrect attempts', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function verifyOtp(VerifyClientOtpRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');
        $code = (string) $request->validated('code');

        // Normal path: the account does not exist yet and is created here.
        $registration = $this->pendingRegistrations->findUnexpired($email);

        if ($registration) {
            return $this->success(
                new ClientAuthResource($this->pendingRegistrations->complete($registration, $code)),
                'Email verified. Welcome to SkillServe!',
            );
        }

        // Legacy path: an account registered before sign-ups were deferred.
        $user = User::query()
            ->where('email', $email)
            ->mobileAccounts()
            ->first();

        if (! $user) {
            throw new ApiException('No account found for this email.', 404);
        }

        $verified = $this->otpService->verify($user, $code);

        if (! $verified->isActive()) {
            throw AccountRestrictedException::for($verified);
        }

        return $this->success(
            new ClientAuthResource($this->authenticationService->issueSession($verified)),
            'Email verified successfully.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/cancel-registration',
        summary: 'Discard a sign-up after the user backs out of email verification',
        description: 'Removes the parked registration (and, for accounts created before sign-ups were deferred, the unverified account itself) so the email can be used again straight away.',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Sign-up cancelled (always returned, even for unknown accounts, to prevent enumeration)', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
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
        summary: 'Resend the sign-up verification OTP',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The email is already verified', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 202, description: 'A new code has been sent', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 404, description: 'No sign-up or account found', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 429, description: 'Resend cooldown active', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function resendOtp(ResendClientOtpRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');

        $registration = $this->pendingRegistrations->findUnexpired($email);

        $user = $registration
            ? null
            : User::query()->where('email', $email)->mobileAccounts()->first();

        if (! $registration && ! $user) {
            throw new ApiException('No account found for this email.', 404);
        }

        if ($user?->hasVerifiedEmail()) {
            return $this->success([], 'Email is already verified.', status: 200);
        }

        try {
            $registration
                ? $this->pendingRegistrations->resend($registration)
                : $this->otpService->issue($user);
        } catch (ApiException $e) {
            throw $e;
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
        summary: 'Sign in with a Google ID token, or start a Google sign-up (mobile)',
        description: <<<'TXT'
            Resolves the Google identity against existing accounts:
             * linked Google account, or an account owning the Google-verified email -> signed in (`registration_required: false`); the account is linked and its email marked verified.
             * no account -> nothing is created. Responds with `registration_required: true` plus a name/email draft for the sign-up form, which is submitted to POST /auth/google/register.
            TXT,
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['id_token'],
            properties: [
                new OA\Property(property: 'id_token', type: 'string'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Signed in, or a sign-up is required', content: new OA\JsonContent(ref: '#/components/schemas/ClientGoogleAuthEnvelope')),
            new OA\Response(response: 401, description: 'Invalid Google token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Account not active, or the email belongs to an administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function google(GoogleClientAuthRequest $request): JsonResponse
    {
        $result = $this->googleAuthService->authenticate($request->validated('id_token'));

        return $this->success(
            new ClientGoogleAuthResource($result),
            $result['registration_required']
                ? 'Tell us a little about yourself to finish signing up.'
                : 'Logged in with Google successfully.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/google/register',
        summary: 'Finish a Google sign-up with the details from the profile form',
        description: 'Creates the customer or provider account for a Google identity that has none, and signs it in. The ID token is re-verified, so the email always comes from Google. Until this call succeeds nothing is written, so abandoning the form leaves no account behind.',
        tags: ['Client Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['id_token', 'first_name', 'last_name', 'role'],
            properties: [
                new OA\Property(property: 'id_token', type: 'string', description: 'The same Google ID token POST /auth/google was called with'),
                new OA\Property(property: 'first_name', type: 'string', maxLength: 255, example: 'Alex'),
                new OA\Property(property: 'last_name', type: 'string', maxLength: 255, example: 'Customer'),
                new OA\Property(property: 'role', type: 'string', enum: ['customer', 'provider'], example: 'customer'),
                new OA\Property(property: 'business_name', type: 'string', maxLength: 255, nullable: true, example: 'Alex Repairs'),
                new OA\Property(property: 'specialization', type: 'string', maxLength: 255, nullable: true, description: 'Required when role is provider', example: 'Home Repair'),
                new OA\Property(property: 'experience_years', type: 'integer', minimum: 0, example: 3),
                new OA\Property(property: 'bio', type: 'string', maxLength: 5000, nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Account created and signed in', content: new OA\JsonContent(ref: '#/components/schemas/ClientAuthEnvelope')),
            new OA\Response(response: 401, description: 'Invalid or expired Google token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Account not active, or the email belongs to an administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function googleRegister(CompleteGoogleRegistrationRequest $request): JsonResponse
    {
        return $this->success(
            new ClientAuthResource($this->googleAuthService->completeRegistration($request->validated())),
            'Account created successfully.',
            status: 201,
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
            new OA\Response(response: 403, description: 'Inactive account, or a sign-up that has not been verified yet (`meta.verification_required`)', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
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

    #[OA\Patch(
        path: '/api/client/v1/auth/me',
        summary: "Update the signed-in account's own profile",
        description: 'Partial update: only the fields present in the body are changed. Email and role are not editable here — changing an address re-opens verification, and the role governs authorization.',
        tags: ['Client Authentication'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'first_name', type: 'string', maxLength: 255, example: 'Juan'),
                new OA\Property(property: 'last_name', type: 'string', maxLength: 255, example: 'Dela Cruz'),
                new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '09171234567'),
                new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true, example: '123 Mabini St, Manila'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated profile', content: new OA\JsonContent(ref: '#/components/schemas/ClientUserEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Token is not a client token or account is inactive', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function updateProfile(UpdateClientProfileRequest $request): JsonResponse
    {
        return $this->success(
            new ClientUserResource($this->profileService->update($request->user(), $request->validated())),
            'Profile updated successfully.',
        );
    }

    #[OA\Post(
        path: '/api/client/v1/auth/me/photo',
        summary: 'Upload or replace the profile photo',
        description: 'Multipart upload. The previous photo is deleted once the new one is stored. The response carries the new absolute URL in `profile_picture`.',
        tags: ['Client Authentication'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['photo'],
                properties: [
                    new OA\Property(property: 'photo', type: 'string', format: 'binary', description: 'JPG, PNG or WebP image, at most 5 MB'),
                ],
            ),
        )),
        responses: [
            new OA\Response(response: 200, description: 'Photo stored', content: new OA\JsonContent(ref: '#/components/schemas/ClientUserEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Token is not a client token or account is inactive', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 422, description: 'Missing, oversized, or non-image file', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function updateProfilePhoto(UpdateClientProfilePhotoRequest $request): JsonResponse
    {
        return $this->success(
            new ClientUserResource($this->profileService->updatePhoto($request->user(), $request->file('photo'))),
            'Profile photo updated successfully.',
        );
    }

    #[OA\Delete(
        path: '/api/client/v1/auth/me/photo',
        summary: 'Remove the profile photo',
        description: 'Deletes the stored file and clears the reference. Succeeds even when no photo is set, so the call is safe to repeat.',
        tags: ['Client Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Photo removed', content: new OA\JsonContent(ref: '#/components/schemas/ClientUserEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
            new OA\Response(response: 403, description: 'Token is not a client token or account is inactive', content: new OA\JsonContent(ref: '#/components/schemas/ApiEnvelope')),
        ],
    )]
    public function deleteProfilePhoto(Request $request): JsonResponse
    {
        return $this->success(
            new ClientUserResource($this->profileService->removePhoto($request->user())),
            'Profile photo removed successfully.',
        );
    }

    #[OA\Get(
        path: '/api/client/v1/auth/me/data-export',
        summary: 'Take a copy of everything the platform holds about this account',
        description: 'Own data only: profile, preferences, provider profile, bookings, reviews, reports filed and support tickets.',
        tags: ['Client Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The account\'s data', content: new OA\JsonContent(ref: '#/components/schemas/AccountDataExportEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active mobile account required'),
        ],
    )]
    public function exportData(Request $request): JsonResponse
    {
        return $this->success(
            $this->accountDataService->export($request->user()),
            'Account data exported.',
        );
    }

    #[OA\Delete(
        path: '/api/client/v1/auth/me',
        summary: 'Delete the signed-in account',
        description: 'Confirmed with the account password. Refused while the account still has open bookings, so the other party is never left mid-job. The account is soft-deleted and every device is signed out; an administrator can restore it from Data Management. There is no separate "pending deletion" state.',
        tags: ['Client Authentication'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['password'],
            properties: [
                new OA\Property(property: 'password', type: 'string', description: 'The account\'s current password'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 1000),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Account deleted and sessions revoked'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Active mobile account required'),
            new OA\Response(response: 422, description: 'Wrong password, or open bookings remain'),
        ],
    )]
    public function deleteAccount(DeleteClientAccountRequest $request): JsonResponse
    {
        $this->accountDataService->delete(
            $request->user(),
            $request->validated('password'),
            $request->validated('reason'),
        );

        return $this->success(null, 'Your account has been deleted.');
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
