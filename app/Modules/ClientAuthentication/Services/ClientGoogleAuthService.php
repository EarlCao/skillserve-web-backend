<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Shared\Enums\AccountRole;
use App\Shared\Exceptions\AccountRestrictedException;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Sign-In for the mobile app.
 *
 * The Flutter app obtains a Google ID token (google_sign_in) and posts it
 * here. We validate it against Google's tokeninfo endpoint and require the
 * audience to match the configured client ID.
 *
 * Google identifies the person; the account password is still required,
 * so Google is never a way into an account on its own.
 *
 * Resolution order, for both the Login and the Sign-up button:
 *  1. an account already linked to this Google `sub`, or
 *  2. an account owning the (Google-verified) email
 *     -> without a password the caller is told one is needed
 *        (`password_required`); with the right one it signs in, linking the
 *        Google account. Accounts made by Google sign-up before passwords
 *        were required set theirs through Forgot password;
 *  3. otherwise NOTHING is written. The caller is told a registration is
 *     required and gets Google's name/email to prefill the sign-up form,
 *     which comes back to {@see startRegistration()}: like an email sign-up
 *     it then confirms an emailed code and chooses a password before the
 *     account exists.
 */
class ClientGoogleAuthService
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function __construct(
        private readonly ClientSessionService $sessionService,
        private readonly PendingRegistrationService $pendingRegistrations,
    ) {}

    /**
     * Sign in with Google and the account password, or report what is
     * missing: the password, or the whole sign-up.
     *
     * @return array<string, mixed> Session payload; `password_required` with
     *                              the account email; or a registration draft
     *                              under `registration_required`.
     */
    public function authenticate(string $idToken, ?string $password = null): array
    {
        $claims = $this->verifyIdToken($idToken);

        $email = strtolower((string) $claims['email']);
        $googleSub = (string) ($claims['sub'] ?? '');

        $user = $this->findLinkedAccount($googleSub) ?? $this->findAccountByEmail($email);

        if ($user) {
            if ($password === null) {
                return [
                    'registration_required' => false,
                    'password_required' => true,
                    'email' => $user->email,
                ];
            }

            if (! Hash::check($password, $user->password)) {
                throw new ApiException('Incorrect password.', 401, errors: ['password' => ['Incorrect password.']]);
            }

            return $this->signIn($user, $googleSub);
        }

        return [
            'registration_required' => true,
            'google' => $this->registrationDraft($claims, $email),
        ];
    }

    /**
     * Start a Google sign-up once the user has supplied their details. The
     * ID token is re-verified, so the caller cannot claim an address it does
     * not own. Like an email sign-up, nothing is created yet: a code goes to
     * the Google address, and the account is made once it is confirmed and a
     * password chosen ({@see PendingRegistrationService}).
     *
     * @param  array{id_token: string, role: string, first_name: string, last_name: string, business_name?: string|null, specialization?: string|null, experience_years?: int|null, bio?: string|null}  $validated
     */
    public function startRegistration(array $validated): PendingRegistration
    {
        $claims = $this->verifyIdToken((string) $validated['id_token']);

        $email = strtolower((string) $claims['email']);
        $googleSub = (string) ($claims['sub'] ?? '');

        // The account may have appeared between the two calls (a second
        // device, or a retry). Signing in needs its password now, so send
        // the user to log in rather than parking a sign-up that would fail.
        if ($this->findLinkedAccount($googleSub) ?? $this->findAccountByEmail($email)) {
            throw new ApiException('This Google account already has a SkillServe account. Log in with Google and your password.', 409);
        }

        if ($validated['role'] === 'provider') {
            ProviderSignups::assertOpen();
        }

        $roleId = $validated['role'] === 'provider'
            ? AccountRole::Provider->value
            : AccountRole::Customer->value;

        return $this->pendingRegistrations->start(
            [...$validated, 'email' => $email],
            $roleId,
            $googleSub !== '' ? $googleSub : null,
        );
    }

    private function findLinkedAccount(string $googleSub): ?User
    {
        if ($googleSub === '') {
            return null;
        }

        return User::query()
            ->where('google_sub', $googleSub)
            ->mobileAccounts()
            ->first();
    }

    private function findAccountByEmail(string $email): ?User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user && ! $user->isMobileAccount()) {
            // Administrators belong to the web console and must not be
            // reachable through the mobile Google button.
            Log::warning('Google sign-in refused for an administrator account.', ['email' => $email]);

            throw new ApiException(
                'This email belongs to a SkillServe administrator account. Sign in on the admin website instead.',
                403,
            );
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function signIn(User $user, string $googleSub): array
    {
        if (! $user->isActive()) {
            throw AccountRestrictedException::for($user);
        }

        $attributes = [];

        // Keep the link current (covers a Google address change) and adopt
        // the account on first Google sign-in.
        if ($googleSub !== '' && $user->google_sub !== $googleSub) {
            $attributes['google_sub'] = $googleSub;
        }

        // Google has already verified the address, so an account that had
        // not finished email verification is verified by signing in here.
        if (! $user->hasVerifiedEmail()) {
            $attributes['email_verified_at'] = now();
            $attributes['email_otp_hash'] = null;
            $attributes['email_otp_expires_at'] = null;
            $attributes['email_otp_attempts'] = 0;
        }

        if ($attributes !== []) {
            $user->forceFill($attributes)->save();
            $user = $user->fresh();
        }

        return array_merge(
            ['registration_required' => false],
            $this->sessionService->issue($user),
        );
    }

    /**
     * The prefill the sign-up form needs: Google's own name parts, falling
     * back to splitting the display name.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, string|null>
     */
    private function registrationDraft(array $claims, string $email): array
    {
        $firstName = trim((string) ($claims['given_name'] ?? ''));
        $lastName = trim((string) ($claims['family_name'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            $parts = preg_split('/\s+/', trim((string) ($claims['name'] ?? ''))) ?: [];
            $firstName = $firstName !== '' ? $firstName : (string) ($parts[0] ?? '');
            $lastName = $lastName !== '' ? $lastName : (string) ($parts[1] ?? '');
        }

        return [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'picture' => isset($claims['picture']) ? (string) $claims['picture'] : null,
        ];
    }

    /**
     * @return array{aud: string, email: string, email_verified: string, exp: string, sub: string, name?: string, given_name?: string, family_name?: string, picture?: string}
     */
    private function verifyIdToken(string $idToken): array
    {
        $response = Http::timeout(10)->get(self::TOKENINFO_URL, [
            'id_token' => $idToken,
        ]);

        if (! $response->successful()) {
            throw new ApiException('The Google sign-in could not be validated.', 401);
        }

        $claims = $response->json();

        $expectedAudience = (string) config('services.google.client_id');

        if (empty($claims['aud']) || $claims['aud'] !== $expectedAudience) {
            Log::warning('Google ID token audience mismatch.', ['aud' => $claims['aud'] ?? null]);
            throw new ApiException('The Google sign-in is not valid for this app.', 401);
        }

        if (empty($claims['email']) || (isset($claims['email_verified']) && ! in_array($claims['email_verified'], ['true', '1', true], true))) {
            throw new ApiException('Your Google account email is not verified.', 401);
        }

        if (isset($claims['exp']) && (int) $claims['exp'] < time()) {
            throw new ApiException('The Google sign-in has expired. Please try again.', 401);
        }

        return $claims;
    }
}
