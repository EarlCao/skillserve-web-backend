<?php

namespace App\Modules\DataManagement\Services;

use App\Models\User;
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
use Illuminate\Support\Facades\DB;

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

        return DB::query()
            ->fromSub($union, 'deleted_records')
            ->orderByDesc('deleted_at')
            ->orderByDesc('resource_id')
            ->paginate($this->perPage($filters), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
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
        DB::transaction(function () use ($model, $type, $id, $actor): void {
            DataArchive::query()->where('resource_type', $type)->where('resource_id', $id)->delete();
            $model->forceDelete();
            activity('data_management')->causedBy($actor)->withProperties(['resource_type' => $type, 'resource_id' => $id])->log('Deleted record permanently removed');
        });
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
