<?php

namespace App\Modules\IdentityVerification\Services;

use App\Models\User;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\Settings\Services\SettingsService;
use Carbon\CarbonImmutable;

/**
 * Whether an account must hold a verified Philippine National ID before it may
 * transact, and whether it does.
 *
 * Two settings decide the first question (System Settings → Identity):
 *
 * - `identity_verification_required` — the master switch. Off by default, so
 *   deploying this feature changes nothing until someone turns it on.
 * - `identity_verification_enforced_from` — the grandfathering date. Accounts
 *   created before it keep transacting unverified; accounts created on or
 *   after it must verify. Empty means every account must verify, which freezes
 *   existing users until the review queue is cleared.
 *
 * Verification state is read per account, never cached across accounts: this
 * is an authorization decision and a stale answer would either block a
 * verified person or admit an unverified one.
 */
class IdentityGate
{
    public function __construct(private readonly SettingsService $settings) {}

    /** Whether [$user] falls under the requirement at all. */
    public function appliesTo(User $user): bool
    {
        if (! $this->settings->value('identity', 'identity_verification_required')) {
            return false;
        }

        $from = trim((string) $this->settings->value('identity', 'identity_verification_enforced_from'));

        // No cutover date: the requirement covers everyone.
        if ($from === '') {
            return true;
        }

        return $user->created_at !== null
            && $user->created_at->gte(CarbonImmutable::parse($from)->startOfDay());
    }

    /** The account's identity status, without creating a record to find out. */
    public function statusFor(User $user): string
    {
        return IdentityVerification::query()
            ->where('user_id', $user->id)
            ->value('status') ?? IdentityVerification::UNVERIFIED;
    }

    /**
     * Whether [$user] may transact as far as identity is concerned. An account
     * the requirement does not cover always may.
     */
    public function allows(User $user): bool
    {
        return ! $this->appliesTo($user)
            || $this->statusFor($user) === IdentityVerification::VERIFIED;
    }
}
