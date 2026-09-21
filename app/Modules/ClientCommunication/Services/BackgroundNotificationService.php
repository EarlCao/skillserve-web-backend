<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Services\BaseService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lets the mobile app's background task show system notifications while the
 * app is closed, without Firebase: the task polls with a narrow token.
 */
class BackgroundNotificationService extends BaseService
{
    public const TOKEN_NAME = 'client-background';

    /** One per device; older ones beyond this are dropped. */
    private const TOKENS_KEPT = 5;

    /** At most this many notifications are returned per check. */
    private const BATCH = 10;

    /**
     * Issue a token that can only read pending notifications. The app keeps
     * it in secure storage for its background task.
     *
     * @return array{token: string, expires_at: string|null}
     */
    public function issueToken(User $user): array
    {
        $token = $user->createToken(
            self::TOKEN_NAME,
            [config('client-auth.background_ability')],
            now()->addMinutes((int) config('client-auth.background_token_expiration')),
        );

        $keep = $user->tokens()
            ->where('name', self::TOKEN_NAME)
            ->latest('id')
            ->limit(self::TOKENS_KEPT)
            ->pluck('id');
        $user->tokens()->where('name', self::TOKEN_NAME)->whereNotIn('id', $keep)->delete();

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Unread notifications created at or after [$after] (the newest one the
     * device already showed), oldest first so they are posted in order.
     * Inclusive because timestamps are per second: the device skips ids it
     * already showed. Without [$after], only the last day is considered, so
     * a first check does not replay an old backlog.
     */
    public function pending(User $user, ?CarbonInterface $after): Collection
    {
        // "Push notifications" off in System Settings: nothing is pushed while
        // the app is closed; the in-app feed still has everything.
        if (! app(SettingsService::class)->value('notifications', 'push_notifications_enabled')) {
            return collect();
        }

        return $user->unreadNotifications()
            ->where('created_at', '>=', $after ?? now()->subDay())
            ->reorder('created_at', 'desc')
            ->limit(self::BATCH)
            ->get()
            ->reverse()
            ->values();
    }
}
