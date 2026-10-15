<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Runs the 2026_10_15_000001 backfill once more. Render runs migrations
 * before the new code takes traffic, so a provider suspended in between — by
 * the old code, which only suspended the profile — kept an active account:
 * still able to sign in, and missing from the dashboard's "Suspended users".
 *
 * Data only and idempotent: it touches only providers whose profile is
 * suspended while the account is active. Rollback does nothing; undoing the
 * suspension is the original migration's rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        (require __DIR__.'/2026_10_15_000001_suspend_accounts_of_suspended_providers.php')->up();
    }

    public function down(): void
    {
        //
    }
};
