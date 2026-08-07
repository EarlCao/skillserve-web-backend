<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Users\Events\UserUnbanned;
use Illuminate\Console\Command;

/**
 * Lifts temporary bans whose duration has elapsed.
 *
 * Banned accounts whose banned_until is in the past return to "active", so
 * the admin list and subsequent logins reflect the lifted ban without manual
 * work. Logins also auto-recover expired bans (see LoginAction) — this batch
 * run just keeps the stored status tidy even when nobody logs in.
 */
class UnbanExpiredUsers extends Command
{
    protected $signature = 'users:unban-expired';

    protected $description = 'Automatically unban users whose temporary ban has expired';

    public function handle(): int
    {
        $now = now();

        $expired = User::query()
            ->where('status', 'banned')
            ->whereNotNull('banned_until')
            ->where('banned_until', '<=', $now)
            ->get();

        foreach ($expired as $user) {
            $user->update([
                'status' => 'active',
                'banned_until' => null,
                'unban_reason' => 'Temporary ban expired.',
                'activated_at' => $now,
                'activated_by' => null,
            ]);

            // Keeps the audit trail and the notification email in sync with
            // the state change (actor is null: system-driven lift).
            event(new UserUnbanned(user: $user, actor: null, reason: 'Temporary ban expired.'));
        }

        $count = $expired->count();
        $this->info("Unbanned {$count} user(s) with expired temporary bans.");

        return self::SUCCESS;
    }
}
