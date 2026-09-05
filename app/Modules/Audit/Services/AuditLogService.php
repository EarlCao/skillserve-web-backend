<?php

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\Activitylog\Models\Activity;

class AuditLogService extends BaseService
{
    private const SORTABLE = ['created_at', 'id'];

    public function index(array $filters): LengthAwarePaginator
    {
        $query = Activity::query()->with('causer:id,name,email');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(log_name, \'\')) LIKE ?', [$term])
                    ->orWhereHas('causer', fn ($causer) => $causer
                        ->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            });
        }

        if (! empty($filters['administrator_id'])) {
            $query->where('causer_type', (new User)->getMorphClass())
                ->where('causer_id', $filters['administrator_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('description', $filters['action']);
        }

        if (! empty($filters['module'])) {
            $query->where('log_name', $filters['module']);
        }

        if (($filters['view'] ?? null) === 'login') {
            $query->whereIn('description', [
                'administrator_logged_in', 'administrator_logged_out', 'administrator_login_failed',
            ]);
        } elseif (($filters['view'] ?? null) === 'security') {
            $query->where(function ($security) {
                $security->whereIn('log_name', ['authentication', 'administrators'])
                    ->orWhereIn('description', [
                        'administrator_password_changed', 'administrator_status_changed',
                        'user_banned', 'user_unbanned', 'user_suspended', 'user_activated', 'user_deleted',
                    ]);
            });
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', now()->parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', now()->parse($filters['to'])->endOfDay());
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate($this->perPage($filters));
    }

    public function administrators(): array
    {
        $activityTable = config('activitylog.table_name', 'activity_log');

        return Activity::query()
            ->whereNotNull('causer_id')
            ->where('causer_type', (new User)->getMorphClass())
            ->join('users', 'users.id', '=', $activityTable.'.causer_id')
            ->whereNull('users.deleted_at')
            ->select('users.id', 'users.name', 'users.email')
            ->distinct()
            ->orderBy('users.name')
            ->get()
            ->map(fn ($user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
            ->all();
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 20)));
    }
}
