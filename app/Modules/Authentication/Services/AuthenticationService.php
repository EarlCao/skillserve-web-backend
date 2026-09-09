<?php

namespace App\Modules\Authentication\Services;

use App\Models\User;
use App\Modules\Authentication\Actions\LoginAction;
use App\Modules\Authentication\Actions\LogoutAction;
use App\Modules\Authentication\Events\AdministratorLoggedIn;
use App\Modules\Authentication\Events\AdministratorLoggedOut;
use App\Modules\Authentication\Resources\AuthResource;
use App\Modules\Settings\Services\SettingsService;

/**
 * Orchestrates authentication: credential validation, token issuance and
 * session invalidation. Controllers stay thin and delegate here.
 */
class AuthenticationService
{
    public function __construct(
        private readonly LoginAction $loginAction,
        private readonly LogoutAction $logoutAction,
        private readonly SettingsService $settingsService,
    ) {}

    /**
     * Authenticate an administrator and issue a Sanctum token.
     *
     * @param  array{email: string, password: string}  $validated
     */
    public function login(array $validated): AuthResource
    {
        $user = $this->loginAction->handle($validated['email'], $validated['password']);

        // Track the last successful login (surfaced by the Administrator
        // Management module).
        $user->fill(['last_login_at' => now()])->save();

        $expiration = (int) $this->settingsService->value('system', 'session_timeout_minutes');

        $token = $user->createToken(
            'admin-session',
            ['*'],
            $expiration ? now()->addMinutes((int) $expiration) : null,
        );

        event(new AdministratorLoggedIn(
            user: $user,
            token: $token->plainTextToken,
            ip: request()->ip(),
            userAgent: request()->userAgent(),
        ));

        return new AuthResource([
            'user' => $user,
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Invalidate the administrator's current session token.
     */
    public function logout(User $user): void
    {
        $this->logoutAction->handle($user);

        event(new AdministratorLoggedOut(
            user: $user,
            ip: request()->ip(),
            userAgent: request()->userAgent(),
        ));
    }
}
