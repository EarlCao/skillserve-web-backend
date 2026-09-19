<?php

namespace App\Modules\DataManagement\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\ClientRefreshToken;
use App\Modules\Bookings\Models\Booking;
use App\Modules\DataManagement\Models\DataArchive;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Reviews\Models\Review;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Modules\Services\Models\Service;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DataManagementService extends BaseService
{
    private const DELETED_LABEL_COLUMNS = [
        'users' => 'name',
        'services' => 'title',
        'bookings' => 'booking_number',
        'reviews' => 'comment',
        'reports' => 'reason',
        'messages' => 'content',
        'service_categories' => 'name',
        'service_subcategories' => 'name',
    ];

    private const MODELS = [
        'users' => User::class,
        'services' => Service::class,
        'bookings' => Booking::class,
        'reviews' => Review::class,
        'reports' => Report::class,
        'messages' => Message::class,
        'service_categories' => ServiceCategory::class,
        'service_subcategories' => ServiceSubcategory::class,
    ];

    /**
     * Rows that still reference a record, as [table, column, label]. A record
     * with any of them (soft-deleted ones included) is never force-deleted:
     * the foreign keys would either cascade and silently destroy the related
     * rows (bookings, provider profiles, services…) or reject the delete.
     */
    private const DEPENDENTS = [
        'users' => [
            ['bookings', 'client_id', 'booking'],
            ['provider_profiles', 'user_id', 'provider profile'],
            ['reviews', 'reviewer_id', 'review'],
            ['messages', 'sender_id', 'sent message'],
            ['messages', 'receiver_id', 'received message'],
        ],
        'services' => [
            ['bookings', 'service_id', 'booking'],
            ['reviews', 'service_id', 'review'],
        ],
        'bookings' => [
            ['reviews', 'booking_id', 'review'],
        ],
        'service_categories' => [
            ['services', 'category_id', 'service'],
            ['service_subcategories', 'category_id', 'subcategory'],
        ],
    ];

    public function archives(array $filters): LengthAwarePaginator
    {
        return DataArchive::query()->with('archivedBy:id,name')->when($filters['resource_type'] ?? null, fn ($q, $type) => $q->where('resource_type', $type))->latest('archived_at')->paginate($this->perPage($filters));
    }

    public function archive(array $data, User $actor): DataArchive
    {
        return DB::transaction(function () use ($data, $actor): DataArchive {
            $model = $this->findModel($data['resource_type'], $data['resource_id'], false, true);
            $archive = DataArchive::query()->firstOrCreate(
                ['resource_type' => $data['resource_type'], 'resource_id' => $model->getKey()],
                ['archived_by' => $actor->id, 'archived_at' => now(), 'previous_state' => ['status' => $model->status]],
            );

            if ($data['resource_type'] === 'services' && $model->status !== 'archived') {
                $model->update(['status' => 'archived']);
            }

            activity('data_management')->causedBy($actor)->withProperties($data)->log('Record archived');

            return $archive->load('archivedBy:id,name');
        });
    }

    public function restoreArchive(DataArchive $archive, User $actor): void
    {
        DB::transaction(function () use ($archive, $actor): void {
            $model = $this->findModel($archive->resource_type, $archive->resource_id);
            if ($archive->resource_type === 'services' && $model->status === 'archived') {
                $model->update(['status' => $archive->previous_state['status'] ?? 'draft']);
            }
            $archive->delete();
            activity('data_management')->causedBy($actor)->withProperties(['resource_type' => $archive->resource_type, 'resource_id' => $archive->resource_id])->log('Record restored');
        });
    }

    public function deleted(array $filters): LengthAwarePaginator
    {
        $type = $filters['resource_type'] ?? null;
        $types = $type ? [$type] : array_keys(self::MODELS);
        $union = null;
        foreach ($types as $resourceType) {
            $class = self::MODELS[$resourceType];
            if (! in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($class), true)) {
                continue;
            }
            $labelColumn = self::DELETED_LABEL_COLUMNS[$resourceType];
            $query = $class::onlyTrashed()
                ->selectRaw(
                    "? AS resource_type, id AS resource_id, COALESCE({$labelColumn}, 'Record ' || CAST(id AS TEXT)) AS label, deleted_at",
                    [$resourceType],
                )
                ->toBase();
            $union = $union ? $union->unionAll($query) : $query;
        }

        if (! $union) {
            return new LengthAwarePaginator([], 0, $this->perPage($filters), (int) ($filters['page'] ?? 1), ['path' => request()->url()]);
        }

        $purgeable = config('data-management.permanent_delete_types');
        $retentionDays = (int) config('data-management.retention_days');

        return DB::query()
            ->fromSub($union, 'deleted_records')
            ->orderByDesc('deleted_at')
            ->orderByDesc('resource_id')
            ->paginate($this->perPage($filters), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)))
            ->through(function (object $record) use ($purgeable, $retentionDays): object {
                $blockedBy = $this->blockingDependents($record->resource_type, (int) $record->resource_id);
                $canPurge = in_array($record->resource_type, $purgeable, true) && $blockedBy === null;
                $record->can_permanently_delete = $canPurge;
                $record->blocked_by = $blockedBy;
                $record->purge_at = $canPurge && $record->deleted_at
                    ? Carbon::parse($record->deleted_at)->addDays($retentionDays)->toIso8601String()
                    : null;

                return $record;
            });
    }

    public function restoreDeleted(string $type, int $id, User $actor): void
    {
        $model = $this->findModel($type, $id, true);
        DB::transaction(function () use ($model, $type, $id, $actor): void {
            $model->restore();

            if ($model instanceof Review) {
                $model->update([
                    'status' => 'active',
                    'removed_by' => null,
                    'removed_at' => null,
                ]);
            }

            activity('data_management')->causedBy($actor)->withProperties(['resource_type' => $type, 'resource_id' => $id])->log('Deleted record restored');
        });
    }

    public function permanentlyDelete(string $type, int $id, User $actor): void
    {
        if (! in_array($type, config('data-management.permanent_delete_types'), true)) {
            throw new ApiException('This record type cannot be permanently deleted through data management.', 422);
        }

        $model = $this->findModel($type, $id, true);

        if ($blockedBy = $this->blockingDependents($type, $id)) {
            throw new ApiException("This record still has related data ({$blockedBy}). Remove or permanently delete those first.", 409);
        }

        DB::transaction(function () use ($model, $type, $id, $actor): void {
            $this->forceDelete($type, $model);
            activity('data_management')->causedBy($actor)->withProperties(['resource_type' => $type, 'resource_id' => $id])->log('Deleted record permanently removed');
        });
    }

    /**
     * Permanently remove purgeable records deleted more than the retention
     * period ago. Returns the number of records removed per type.
     *
     * @return array<string, int>
     */
    public function purgeExpired(): array
    {
        $cutoff = now()->subDays((int) config('data-management.retention_days'));
        $purged = [];

        foreach (config('data-management.permanent_delete_types') as $type) {
            $purged[$type] = 0;

            self::MODELS[$type]::onlyTrashed()
                ->where('deleted_at', '<=', $cutoff)
                ->chunkById(100, function ($models) use ($type, &$purged): void {
                    foreach ($models as $model) {
                        // Kept until its related records are gone.
                        if ($this->blockingDependents($type, (int) $model->getKey())) {
                            continue;
                        }

                        DB::transaction(fn () => $this->forceDelete($type, $model));
                        $purged[$type]++;
                    }
                });
        }

        if (array_sum($purged) > 0) {
            activity('data_management')->withProperties(['purged' => $purged])->log('Expired deleted records permanently removed');
        }

        return $purged;
    }

    /**
     * Human-readable list of rows still referencing the record (e.g.
     * "2 bookings, 1 review"), or null when it can be force-deleted.
     */
    private function blockingDependents(string $type, int $id): ?string
    {
        $found = [];

        foreach (self::DEPENDENTS[$type] ?? [] as [$table, $column, $label]) {
            $count = DB::table($table)->where($column, $id)->count();

            if ($count > 0) {
                $found[] = $count.' '.Str::plural($label, $count);
            }
        }

        return $found === [] ? null : implode(', ', $found);
    }

    private function forceDelete(string $type, Model $model): void
    {
        DataArchive::query()->where('resource_type', $type)->where('resource_id', $model->getKey())->delete();

        if ($model instanceof User) {
            // Login tokens and role links: they would otherwise block (refresh
            // tokens) or outlive (Sanctum tokens, role pivot rows) the account.
            ClientRefreshToken::query()->where('user_id', $model->getKey())->delete();
            $model->tokens()->delete();
            DB::table(config('permission.table_names.model_has_roles'))
                ->where(config('permission.column_names.model_morph_key'), $model->getKey())
                ->where('model_type', $model->getMorphClass())
                ->delete();
        }

        $model->forceDelete();
    }

    private function findModel(string $type, int $id, bool $trashed = false, bool $lock = false): Model
    {
        $class = self::MODELS[$type] ?? null;
        if (! $class) {
            throw new ApiException('This record type is not supported.', 422);
        }
        $query = $class::query();
        if ($trashed) {
            $query->onlyTrashed();
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
