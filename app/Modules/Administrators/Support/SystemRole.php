<?php

namespace App\Modules\Administrators\Support;

/**
 * Well-known role names the Administrator Management module must protect.
 */
final class SystemRole
{
    /**
     * The bootstrap role. It bypasses every gate (see AppServiceProvider) and
     * must never be deleted, renamed, or lose its permissions.
     */
    public const SUPER_ADMIN = 'super-admin';
}
