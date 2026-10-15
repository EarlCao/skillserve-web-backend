<?php

use App\Shared\Enums\AccountRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One National ID may now back one *provider* account and one *customer*
 * account (owner decision 2026-10-15), instead of one account of any kind —
 * a person may both hire and offer services, but never hold two provider (or
 * two customer) accounts on one ID.
 *
 * - `account_type` ('customer' | 'provider') on identity_verifications,
 *   backfilled from the account's role (an account's type never changes
 *   after sign-up). Records whose account was permanently deleted keep null:
 *   they are released and outside the rule.
 * - The unique index becomes (id_number_hash, account_type), counting only
 *   pending and verified submissions. A rejected submission no longer holds
 *   the ID, so someone who tried another person's card cannot lock the real
 *   owner out; its hash stays on file for fraud review.
 *
 * Data impact: one nullable column and an index swap; existing rows already
 * satisfy the stricter old rule, so the new index cannot fail to build.
 * Deploy with the code (the code writes account_type on every submission).
 *
 * Rollback restores the old one-account-per-ID index. It fails if any ID is
 * by then shared by a customer and a provider account (or held by a rejected
 * and a live submission) — resolve those rows first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table): void {
            $table->string('account_type', 16)->nullable()->after('user_id');
        });

        DB::table('identity_verifications')
            ->whereNotNull('user_id')
            ->update([
                'account_type' => DB::raw(sprintf(
                    "(CASE WHEN (SELECT role_id FROM users WHERE users.id = identity_verifications.user_id) = %d THEN 'provider' ELSE 'customer' END)",
                    AccountRole::Provider->value,
                )),
            ]);

        DB::statement('DROP INDEX IF EXISTS identity_verifications_active_id_number');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX identity_verifications_active_id_number_per_type
            ON identity_verifications (id_number_hash, account_type)
            WHERE id_number_hash IS NOT NULL AND released_at IS NULL AND status IN ('pending', 'verified')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS identity_verifications_active_id_number_per_type');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX identity_verifications_active_id_number
            ON identity_verifications (id_number_hash)
            WHERE id_number_hash IS NOT NULL AND released_at IS NULL
        SQL);

        Schema::table('identity_verifications', function (Blueprint $table): void {
            $table->dropColumn('account_type');
        });
    }
};
