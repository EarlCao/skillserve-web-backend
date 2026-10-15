<?php

namespace App\Modules\ClientAuthentication\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An administrator suspended, banned or deleted the account, pushed to the
 * user's open app so it signs out at once and shows why — instead of waiting
 * for the next request to be refused. The tokens are already revoked; this
 * only spares the user from a screen that no longer works.
 *
 * `account` has the same shape as `meta.account` on a refused request
 * ({status, reason, since, until}), with `status` "deleted" for a removed
 * account.
 */
class ClientAccountRestricted implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array{status: string, reason: ?string, since: ?string, until: ?string}  $account
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $account,
        public readonly string $message,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'client.account.restricted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['account' => $this->account, 'message' => $this->message];
    }
}
