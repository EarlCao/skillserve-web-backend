<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Google Sign-In for the mobile app.
 *
 * The Flutter app obtains a Google ID token (google_sign_in) and posts it
 * here. We validate it against Google's tokeninfo endpoint, require the
 * audience to match the configured mobile client ID, and find-or-create the
 * matching mobile account. Google-verified emails skip the OTP flow.
 */
class ClientGoogleAuthService
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function __construct(
        private readonly ClientSessionService $sessionService,
    ) {}

    /**
     * @return array<string, mixed> Session payload (user + tokens).
     */
    public function authenticate(string $idToken): array
    {
        $claims = $this->verifyIdToken($idToken);

        $email = strtolower((string) $claims['email']);

        $user = User::query()
            ->where('email', $email)
            ->whereIn('user_type', ['customer', 'provider'])
            ->doesntHave('roles')
            ->first();

        if (! $user) {
            $user = $this->createAccountFromGoogle($claims, $email);
        }

        if (! $user->isActive()) {
            throw new ApiException('Your account is not active.', 403);
        }

        // Trust Google's email verification.
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $user = $user->fresh();
        }

        return $this->sessionService->issue($user);
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

    /**
     * @param  array<string, mixed>  $claims
     */
    private function createAccountFromGoogle(array $claims, string $email): User
    {
        $firstName = (string) ($claims['given_name'] ?? '');
        $lastName = (string) ($claims['family_name'] ?? '');

        if ($firstName === '' || $lastName === '') {
            $parts = preg_split('/\s+/', trim((string) ($claims['name'] ?? $email))) ?: [];
            $firstName = $parts[0] ?? 'Google';
            $lastName = $parts[1] ?? 'User';
        }

        // `email_verified_at` is not mass-assignable; Google has already
        // verified the email, so mark and persist it immediately.
        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim($firstName.' '.$lastName),
            'email' => $email,
            // Not used for Google logins, but the column requires a hash.
            'password' => Str::random(32),
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
