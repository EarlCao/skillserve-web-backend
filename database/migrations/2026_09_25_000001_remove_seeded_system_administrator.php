<?php

use App\Models\User;
use App\Shared\Enums\AccountRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the second administrator that earlier seeding created
 * (`system@skillserve.test`, or whatever `SYSTEM_ADMIN_EMAIL` was set to), so
 * a deployment is left with exactly one way in: the super-admin.
 *
 * Why a migration rather than a seeder change: production is already seeded,
 * and `db:seed-if-empty` skips once roles exist, so editing the seeder alone
 * would never touch the live database. Migrations run on every deploy, which
 * is what makes this take effect on the next push.
 *
 * ## Data impact
 *
 * **Soft** deletion only, matching how every other account is removed
 * ([[ADR-009]] / ADR-016): the row stays, its Sanctum tokens are revoked and
 * its staff role is detached, so it can no longer sign in or act. An
 * administrator can restore it from Data Management within the 30-day
 * retention window; after that the scheduled purge removes it for good.
 *
 * Nothing else is touched. Administrator accounts created by hand are left
 * alone — this removes only the account the seeder used to create. Audit
 * entries it caused survive: `activity_log.causer` is a nullable morph with no
 * foreign key, so the history of what it did remains readable.
 *
 * Idempotent and safe on a database that never had the account (a fresh
 * install, or the test database), where it does nothing.
 *
 * ## Deployment order and rollback
 *
 * Run any time; it does not depend on application code. `down()` cannot
 * responsibly recreate the account — its password was environment-derived and
 * is not recoverable — so it restores the soft-deleted row if it is still
 * there and otherwise does nothing. Recreate the account through the
 * Administrator Management module if it is ever wanted again.
 */
return new class extends Migration
{
    private function email(): string
    {
        return (string) env('SYSTEM_ADMIN_EMAIL', 'system@skillserve.test');
    }

    public function up(): void
    {
        $account = User::query()->where('email', $this->email())->first();

        if ($account === null) {
            return;
        }

        // Never touch the super-admin, whatever the configured email says.
        if ((int) $account->role_id === AccountRole::SuperAdmin->value) {
            return;
        }

        DB::transaction(function () use ($account): void {
            $account->tokens()->delete();

            DB::table(config('permission.table_names.model_has_roles'))
                ->where(config('permission.column_names.model_morph_key'), $account->getKey())
                ->where('model_type', $account->getMorphClass())
                ->delete();

            $account->delete();
        });
    }

    public function down(): void
    {
        User::withTrashed()
            ->where('email', $this->email())
            ->whereNotNull('deleted_at')
            ->restore();
    }
};
