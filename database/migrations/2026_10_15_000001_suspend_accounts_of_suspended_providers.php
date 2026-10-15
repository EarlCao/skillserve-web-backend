<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Suspending a provider now suspends their account too, so they are signed
 * out and cannot sign in. Providers suspended before that change still have
 * an active account: this gives them the same suspension (date, admin and
 * reason copied from the profile) and ends their sessions.
 *
 * Data only. Touches only providers whose profile is suspended and whose
 * account is active and not deleted. Rollback returns those accounts to
 * active, matched on the copied suspension date and admin — which a provider
 * suspended after this deploy also matches, so roll back only right after
 * deploying. Revoked sessions stay revoked, which only means signing in again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $profiles = DB::table('provider_profiles')
            ->join('users', 'users.id', '=', 'provider_profiles.user_id')
            ->whereNotNull('provider_profiles.suspended_at')
            ->where('users.status', 'active')
            ->whereNull('users.deleted_at')
            ->get([
                'provider_profiles.user_id',
                'provider_profiles.suspended_at',
                'provider_profiles.suspended_by',
                'provider_profiles.suspension_reason',
            ]);

        foreach ($profiles as $profile) {
            DB::transaction(function () use ($profile): void {
                DB::table('users')->where('id', $profile->user_id)->update([
                    'status' => 'suspended',
                    'suspended_at' => $profile->suspended_at,
                    'suspended_by' => $profile->suspended_by,
                    'suspension_reason' => $profile->suspension_reason ?: 'Suspended in Provider Management.',
                    'updated_at' => now(),
                ]);

                DB::table('personal_access_tokens')
                    ->where('tokenable_type', (new User)->getMorphClass())
                    ->where('tokenable_id', $profile->user_id)
                    ->delete();

                DB::table('client_refresh_tokens')
                    ->where('user_id', $profile->user_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            });
        }
    }

    public function down(): void
    {
        $profiles = DB::table('provider_profiles')
            ->join('users', 'users.id', '=', 'provider_profiles.user_id')
            ->whereNotNull('provider_profiles.suspended_at')
            ->where('users.status', 'suspended')
            ->whereColumn('users.suspended_at', 'provider_profiles.suspended_at')
            ->get(['provider_profiles.user_id', 'provider_profiles.suspended_by', 'users.suspended_by as user_suspended_by']);

        foreach ($profiles as $profile) {
            if ($profile->suspended_by !== $profile->user_suspended_by) {
                continue;
            }

            DB::table('users')->where('id', $profile->user_id)->update([
                'status' => 'active',
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
                'updated_at' => now(),
            ]);
        }
    }
};
