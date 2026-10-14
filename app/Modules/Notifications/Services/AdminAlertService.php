<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Notifications\AdminAlertNotification;
use App\Shared\Helpers\PageSize;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The admin Inbox: alerts sent to the administrators who can act on them, and
 * each administrator's own list of them.
 */
class AdminAlertService extends BaseService
{
    /**
     * Sends [alert] to every active administrator who may use [ability] —
     * the same check that lets them open the page it links to — except the
     * one who caused it. Sent once the surrounding transaction commits, so an
     * alert never points at something that was rolled back; a failure is
     * logged and never fails the user's own action.
     *
     * @param  mixed  $arguments  the policy argument, e.g. a model class
     */
    public function send(AdminAlertNotification $alert, string $ability, mixed $arguments = [], ?User $actor = null): void
    {
        $this->deliver($alert, fn (): Collection => User::query()
            ->administrators()
            ->where('status', 'active')
            ->when($actor, fn ($query) => $query->whereKeyNot($actor->id))
            ->get()
            ->filter(fn (User $admin): bool => $admin->can($ability, $arguments)));
    }

    /** Sends [alert] to one administrator — the one a ticket is assigned to. */
    public function sendTo(AdminAlertNotification $alert, User $admin): void
    {
        $this->deliver($alert, fn (): Collection => collect([$admin]));
    }

    /** @param  callable(): Collection<int, User>  $recipients */
    private function deliver(AdminAlertNotification $alert, callable $recipients): void
    {
        DB::afterCommit(function () use ($alert, $recipients): void {
            try {
                $admins = $recipients();

                if ($admins->isNotEmpty()) {
                    Notification::send($admins, $alert);
                }
            } catch (Throwable $exception) {
                Log::warning('An admin alert could not be sent.', ['error' => $exception->getMessage()]);
            }
        });
    }

    /** The administrator's alerts, newest first; only unread ones when `status` is `unread`. */
    public function index(User $admin, array $filters): LengthAwarePaginator
    {
        return $this->alerts($admin)
            ->when(($filters['status'] ?? null) === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->paginate(PageSize::from($filters));
    }

    public function unreadCount(User $admin): int
    {
        return $this->alerts($admin)->whereNull('read_at')->count();
    }

    /** Marks one of the administrator's own alerts read; another's id is a 404. */
    public function markRead(User $admin, string $id): DatabaseNotification
    {
        $alert = $this->alerts($admin)->whereKey($id)->firstOrFail();
        $alert->markAsRead();

        return $alert->fresh();
    }

    /** @return int how many were unread */
    public function readAll(User $admin): int
    {
        return $this->alerts($admin)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Only Inbox alerts — never another kind of notification on the account. */
    private function alerts(User $admin): MorphMany
    {
        return $admin->notifications()->where('type', AdminAlertNotification::class);
    }
}
