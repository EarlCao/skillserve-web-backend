<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\ClientCommunication\Events\ClientNotificationsChanged;
use App\Shared\Helpers\PageSize;
use App\Shared\Services\BaseService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClientNotificationService extends BaseService
{
    /** The feed (newest first), or the archive when `archived` is true. */
    public function index(User $client, array $filters): LengthAwarePaginator
    {
        return $client->notifications()
            ->when(
                (bool) ($filters['archived'] ?? false),
                fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at'),
            )
            ->latest()
            ->paginate($this->perPage($filters));
    }

    /** Unread notifications in the feed; archived ones never count. */
    public function unreadCount(User $client): int
    {
        return $client->notifications()->whereNull('read_at')->whereNull('archived_at')->count();
    }

    /**
     * Moves the user's own notifications to the archive, marking them read.
     *
     * @param  array<int, string>  $ids
     * @return int how many moved
     */
    public function archive(User $client, array $ids): int
    {
        $count = $client->notifications()
            ->whereKey($ids)
            ->whereNull('archived_at')
            ->update(['archived_at' => now(), 'read_at' => DB::raw('COALESCE(read_at, CURRENT_TIMESTAMP)')]);

        return $this->changed($client, $count);
    }

    /**
     * Brings archived notifications back to the feed.
     *
     * @param  array<int, string>  $ids
     */
    public function unarchive(User $client, array $ids): int
    {
        $count = $client->notifications()->whereKey($ids)->whereNotNull('archived_at')->update(['archived_at' => null]);

        return $this->changed($client, $count);
    }

    /**
     * Deletes the user's own notifications for good. Another user's ids are
     * ignored, never deleted.
     *
     * @param  array<int, string>  $ids
     */
    public function delete(User $client, array $ids): int
    {
        return $this->changed($client, $client->notifications()->whereKey($ids)->delete());
    }

    /**
     * Tells the user's other open sessions to reload. Best effort: a missing
     * WebSocket server never fails the change itself.
     */
    private function changed(User $client, int $count): int
    {
        if ($count > 0) {
            try {
                event(new ClientNotificationsChanged((int) $client->id));
            } catch (Throwable $exception) {
                Log::warning('Notification change could not be broadcast.', ['error' => $exception->getMessage()]);
            }
        }

        return $count;
    }

    public function markRead(User $client, string $notificationId): DatabaseNotification
    {
        $notification = $client->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return $notification->fresh();
    }

    public function readAll(User $client): int
    {
        return $client->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function perPage(array $filters): int
    {
        return PageSize::from($filters);
    }
}
