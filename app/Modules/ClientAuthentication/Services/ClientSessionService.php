<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\ClientRefreshToken;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientSessionService
{
    /**
     * Issue a short-lived Sanctum access token and a rotating opaque refresh
     * token. The raw refresh value is returned only once.
     *
     * @return array<string, mixed>
     */
    public function issue(User $user, ?string $familyId = null): array
    {
        $expiresAt = now()->addMinutes((int) config('client-auth.access_token_expiration'));
        $accessToken = $user->createToken(
            'client-access',
            [config('client-auth.access_ability')],
            $expiresAt,
        );
        $refreshValue = Str::random(96);
        $refreshExpiresAt = now()->addMinutes((int) config('client-auth.refresh_token_expiration'));

        $refreshToken = ClientRefreshToken::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'family_id' => $familyId ?? (string) Str::uuid(),
            'token_hash' => hash('sha256', $refreshValue),
            'expires_at' => $refreshExpiresAt,
        ]);

        return [
            'user' => $user,
            'token' => $accessToken->plainTextToken,
            'expires_at' => $accessToken->accessToken->expires_at?->toIso8601String(),
            'refresh_token' => $refreshValue,
            'refresh_expires_at' => $refreshToken->expires_at->toIso8601String(),
            'refresh_token_id' => $refreshToken->id,
        ];
    }

    /**
     * Atomically revoke the presented refresh token and replace it.
     *
     * @return array<string, mixed>
     */
    public function rotate(string $value): array
    {
        $result = DB::transaction(function () use ($value): array|ApiException {
            $refreshToken = ClientRefreshToken::query()
                ->where('token_hash', hash('sha256', $value))
                ->lockForUpdate()
                ->first();

            if (! $refreshToken) {
                return new ApiException('The refresh token is invalid.', 401);
            }

            if ($refreshToken->revoked_at !== null) {
                $this->revokeFamily($refreshToken->family_id);

                return new ApiException('The refresh token is invalid.', 401);
            }

            if ($refreshToken->expires_at->isPast()) {
                $refreshToken->update(['revoked_at' => now()]);

                return new ApiException('The refresh token has expired.', 401);
            }

            $user = $refreshToken->user;
            if (! $user || ! $user->isMobileAccount() || ! $user->isActive()) {
                $this->revokeFamily($refreshToken->family_id);

                return new ApiException('Your account is not active.', 403);
            }

            if (! $user->hasVerifiedEmail()) {
                $this->revokeFamily($refreshToken->family_id);

                return new ApiException('Please verify your email address before refreshing your session.', 403);
            }

            $refreshToken->update(['revoked_at' => now()]);
            $issued = $this->issue($user, $refreshToken->family_id);
            $refreshToken->update(['replaced_by' => $issued['refresh_token_id']]);

            return $issued;
        });

        if ($result instanceof ApiException) {
            throw $result;
        }

        return $result;
    }

    public function revokeAll(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $this->revokeRefreshTokens($user);
        });
    }

    public function revokeRefreshTokens(User $user): void
    {
        ClientRefreshToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function revokeFamily(string $familyId): void
    {
        ClientRefreshToken::query()
            ->where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
