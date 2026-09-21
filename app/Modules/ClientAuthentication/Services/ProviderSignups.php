<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Modules\Settings\Services\SettingsService;
use App\Shared\Exceptions\ApiException;

/** System Settings → Marketplace → "Allow provider registration". */
final class ProviderSignups
{
    public static function isOpen(): bool
    {
        return (bool) app(SettingsService::class)->value('marketplace', 'provider_registration_enabled');
    }

    public static function assertOpen(): void
    {
        if (! self::isOpen()) {
            throw new ApiException(
                'Provider sign-ups are closed right now. You can still join as a customer.',
                403,
                errors: ['role' => ['Provider registration is currently turned off.']],
            );
        }
    }
}
