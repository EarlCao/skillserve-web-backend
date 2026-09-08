<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Jobs\SendAnnouncementJob;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Notifications\AnnouncementNotification;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class AnnouncementService extends BaseService
{
    private const SORTABLE = ['created_at', 'scheduled_at', 'status'];

    public function __construct(private readonly SettingsService $settingsService) {}

    public function index(array $filters): LengthAwarePaginator
    {
        $query = Announcement::query()->with('creator:id,name');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(title) LIKE ?', [$term])
                ->orWhereRaw('LOWER(message) LIKE ?', [$term]));
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if ($target = trim((string) ($filters['target'] ?? ''))) {
            $query->where('target', $target);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->paginate($this->perPage($filters));
    }

    public function recipients(string $target, ?string $search = null): array
    {
        $query = User::query()
            ->where('status', 'active')
            ->whereDoesntHave('roles');

        if (in_array($target, ['customers', 'providers'], true)) {
            $query->where('user_type', $target === 'customers' ? 'customer' : 'provider');
        }

        if ($search = trim((string) $search)) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
        }

        return $query->orderBy('name')->limit(100)->get(['id', 'name', 'email', 'user_type'])->toArray();
    }

    /**
     * Create an announcement and either deliver it or queue its scheduled delivery.
     */
    public function store(array $data, User $actor): Announcement
    {
        if (! $this->settingsService->value('notifications', 'announcement_notifications_enabled')) {
            throw new ApiException('Announcements are currently disabled.', 422);
        }

        $announcement = $this->transaction(function () use ($data, $actor): Announcement {
            $recipientIds = $this->recipientQuery($data['target'], $data['recipient_ids'] ?? null)
                ->pluck('id')
                ->values()
                ->all();

            $scheduledAt = isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;
            $announcement = Announcement::query()->create([
                'created_by' => $actor->id,
                'title' => trim($data['title']),
                'message' => trim($data['message']),
                'target' => $data['target'],
                'recipient_ids' => $recipientIds,
                'recipient_count' => count($recipientIds),
                'status' => $scheduledAt ? 'scheduled' : 'pending',
                'scheduled_at' => $scheduledAt,
            ]);

            return $announcement->load('creator:id,name');
        });

        SendAnnouncementJob::dispatch($announcement)
            ->delay($announcement->scheduled_at ?? now())
            ->afterCommit();

        return $announcement;
    }

    public function deliver(Announcement $announcement): void
    {
        if (! $this->settingsService->value('notifications', 'announcement_notifications_enabled')) {
            $announcement->update(['status' => 'failed']);

            return;
        }

        $this->transaction(function () use ($announcement): void {
            $locked = Announcement::query()->lockForUpdate()->find($announcement->id);

            if (! $locked || $locked->status === 'sent') {
                return;
            }

            User::query()
                ->whereIn('id', $locked->recipient_ids ?? [])
                ->where('status', 'active')
                ->whereDoesntHave('roles')
                ->chunkById(250, function ($users) use ($locked): void {
                    Notification::send($users, new AnnouncementNotification($locked));
                });

            $locked->update(['status' => 'sent', 'sent_at' => now()]);
        });
    }

    private function recipientQuery(string $target, ?array $ids = null)
    {
        $query = User::query()->where('status', 'active')->whereDoesntHave('roles');

        if ($target === 'customers') {
            $query->where('user_type', 'customer');
        } elseif ($target === 'providers') {
            $query->where('user_type', 'provider');
        } elseif ($target === 'selected') {
            $query->whereIn('id', $ids ?? []);
        } else {
            $query->whereIn('user_type', ['customer', 'provider']);
        }

        return $query;
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
