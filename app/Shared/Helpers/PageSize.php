<?php

namespace App\Shared\Helpers;

use App\Modules\Settings\Services\SettingsService;

/**
 * How many rows a list endpoint returns: the caller's `per_page` when given,
 * otherwise System Settings → System → Default page size; always 1–100.
 */
final class PageSize
{
    public static function from(array $filters): int
    {
        $requested = $filters['per_page'] ?? app(SettingsService::class)->value('system', 'default_page_size');

        return max(1, min(100, (int) ($requested ?: 15)));
    }
}
