<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Shared\Services\BaseService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class ClientNotificationService extends BaseService
{
    public function index(User $client, array $filters): LengthAwarePaginator
    {
        return $client->notifications()
            ->latest()
            ->paginate($this->perPage($filters));
    }

    public function unreadCount(User $client): int
    {
        return $client->notifications()->whereNull('read_at')->count();
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
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
