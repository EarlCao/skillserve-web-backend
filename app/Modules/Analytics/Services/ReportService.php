<?php

namespace App\Modules\Analytics\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use App\Shared\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * Generates the Reports & Analytics read models.
 *
 * Each report type maps to an existing module's data and produces a flat,
 * tabular shape that is shared between the on-screen table and the CSV
 * export. Controllers stay thin.
 */
class ReportService extends BaseService
{
    private const SORTABLE = ['created_at', 'id'];

    /**
     * Ordered [field => label] mapping for each report type.
     */
    public function columns(string $type): array
    {
        return match ($type) {
            'users' => [
                'id' => 'ID', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone',
                'user_type' => 'Type', 'status' => 'Status', 'activities_count' => 'Activities',
                'last_login_at' => 'Last login', 'created_at' => 'Registered',
            ],
            'providers' => [
                'id' => 'ID', 'business_name' => 'Business name', 'owner' => 'Owner', 'email' => 'Email',
                'verification_status' => 'Verification', 'services_count' => 'Services',
                'total_bookings' => 'Bookings', 'completed_bookings' => 'Completed',
                'average_rating' => 'Avg rating', 'total_reviews' => 'Reviews', 'created_at' => 'Registered',
            ],
            'services' => [
                'id' => 'ID', 'title' => 'Service', 'provider' => 'Provider', 'category' => 'Category',
                'status' => 'Status', 'approval_status' => 'Approval', 'price' => 'Price',
                'total_bookings' => 'Bookings', 'average_rating' => 'Avg rating',
                'total_reviews' => 'Reviews', 'is_featured' => 'Featured', 'created_at' => 'Created',
            ],
            'bookings' => [
                'id' => 'ID', 'booking_number' => 'Booking #', 'client' => 'Client', 'provider' => 'Provider',
                'service' => 'Service', 'status' => 'Status', 'payment_status' => 'Payment',
                'total_price' => 'Total', 'scheduled_date' => 'Scheduled', 'created_at' => 'Created',
            ],
            'reviews' => [
                'id' => 'ID', 'reviewer' => 'Reviewer', 'provider' => 'Provider', 'service' => 'Service',
                'rating' => 'Rating', 'comment' => 'Comment', 'status' => 'Status',
                'is_reported' => 'Reported', 'created_at' => 'Created',
            ],
            'activity' => [
                'id' => 'ID', 'description' => 'Action', 'log_name' => 'Module', 'actor' => 'Administrator',
                'subject_type' => 'Subject', 'created_at' => 'Logged at',
            ],
        };
    }

    /**
     * Paginated report rows for a given type and filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function generate(string $type, array $filters): LengthAwarePaginator
    {
        $query = $this->buildQuery($type, $filters);

        return $query
            ->orderBy($this->sort($filters), $this->direction($filters))
            ->paginate($this->perPage($filters))
            ->through(fn ($model) => $this->mapRow($type, $model));
    }

    /**
     * All matching report rows for export (bounded by the caller).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $type, array $filters): array
    {
        return $this->buildQuery($type, $filters)
            ->orderBy($this->sort($filters), $this->direction($filters))
            ->limit(5000)
            ->get()
            ->map(fn ($model) => $this->mapRow($type, $model))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildQuery(string $type, array $filters): Builder
    {
        $query = match ($type) {
            'users' => User::query()
                ->whereDoesntHave('roles')
                ->withCount('activities'),
            'providers' => ProviderProfile::query()
                ->with(['user:id,name,email'])
                ->withCount('services'),
            'services' => Service::query()
                ->with(['provider:id,business_name,user_id', 'category:id,name']),
            'bookings' => Booking::query()
                ->with(['client:id,name,email', 'provider:id,business_name', 'service:id,title']),
            'reviews' => Review::query()
                ->with(['reviewer:id,name,email', 'provider:id,business_name', 'service:id,title']),
            'activity' => Activity::query()
                ->with('causer:id,name'),
        };

        $this->applyDateRange($query, $type, $filters);
        $this->applySearch($query, $type, $filters);
        $this->applyStatus($query, $type, $filters);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDateRange(Builder $query, string $type, array $filters): void
    {
        $column = 'created_at';

        if ($from = $filters['from'] ?? null) {
            $query->where($column, '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $filters['to'] ?? null) {
            $query->where($column, '<=', Carbon::parse($to)->endOfDay());
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applySearch(Builder $query, string $type, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search === '') {
            return;
        }

        $term = '%'.mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search)).'%';

        match ($type) {
            'users' => $query->where(function ($q) use ($term, $search): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$term]);
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            }),
            'providers' => $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(business_name) LIKE ?', [$term])
                    ->orWhereHas('user', fn ($u) => $u
                        ->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            }),
            'services' => $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereHas('provider', fn ($p) => $p->whereRaw('LOWER(business_name) LIKE ?', [$term]))
                    ->orWhereHas('category', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]));
            }),
            'bookings' => $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(booking_number) LIKE ?', [$term])
                    ->orWhereHas('client', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]))
                    ->orWhereHas('provider', fn ($p) => $p->whereRaw('LOWER(business_name) LIKE ?', [$term]))
                    ->orWhereHas('service', fn ($s) => $s->whereRaw('LOWER(title) LIKE ?', [$term]));
            }),
            'reviews' => $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(comment) LIKE ?', [$term])
                    ->orWhereHas('reviewer', fn ($r) => $r->whereRaw('LOWER(name) LIKE ?', [$term]))
                    ->orWhereHas('provider', fn ($p) => $p->whereRaw('LOWER(business_name) LIKE ?', [$term]))
                    ->orWhereHas('service', fn ($s) => $s->whereRaw('LOWER(title) LIKE ?', [$term]));
            }),
            'activity' => $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(log_name, \'\')) LIKE ?', [$term])
                    ->orWhereHas('causer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]));
            }),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyStatus(Builder $query, string $type, array $filters): void
    {
        $status = trim((string) ($filters['status'] ?? ''));

        if ($status === '') {
            return;
        }

        match ($type) {
            'users' => $query->where('status', $status),
            'providers' => $query->where('verification_status', $status),
            'services' => $query->where('status', $status),
            'bookings' => $query->where('status', $status),
            'reviews' => $query->where('status', $status),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(string $type, $model): array
    {
        return match ($type) {
            'users' => [
                'id' => $model->id,
                'name' => $model->name,
                'email' => $model->email,
                'phone' => $model->phone,
                'user_type' => $model->user_type,
                'status' => $model->status,
                'activities_count' => (int) ($model->activities_count ?? 0),
                'last_login_at' => $model->last_login_at?->toIso8601String(),
                'created_at' => $model->created_at?->toIso8601String(),
            ],
            'providers' => [
                'id' => $model->id,
                'business_name' => $model->business_name,
                'owner' => $model->user?->name,
                'email' => $model->user?->email,
                'verification_status' => $model->verification_status,
                'services_count' => (int) ($model->services_count ?? 0),
                'total_bookings' => (int) $model->total_bookings,
                'completed_bookings' => (int) $model->completed_bookings,
                'average_rating' => $model->average_rating,
                'total_reviews' => (int) $model->total_reviews,
                'created_at' => $model->created_at?->toIso8601String(),
            ],
            'services' => [
                'id' => $model->id,
                'title' => $model->title,
                'provider' => $model->provider?->business_name,
                'category' => $model->category?->name,
                'status' => $model->status,
                'approval_status' => $model->approval_status,
                'price' => $model->price,
                'total_bookings' => (int) $model->total_bookings,
                'average_rating' => $model->average_rating,
                'total_reviews' => (int) $model->total_reviews,
                'is_featured' => (bool) $model->is_featured,
                'created_at' => $model->created_at?->toIso8601String(),
            ],
            'bookings' => [
                'id' => $model->id,
                'booking_number' => $model->booking_number,
                'client' => $model->client?->name,
                'provider' => $model->provider?->business_name,
                'service' => $model->service?->title,
                'status' => $model->status,
                'payment_status' => $model->payment_status,
                'total_price' => $model->total_price,
                'scheduled_date' => $model->scheduled_date?->toIso8601String(),
                'created_at' => $model->created_at?->toIso8601String(),
            ],
            'reviews' => [
                'id' => $model->id,
                'reviewer' => $model->reviewer?->name,
                'provider' => $model->provider?->business_name,
                'service' => $model->service?->title,
                'rating' => (int) $model->rating,
                'comment' => $model->comment,
                'status' => $model->status,
                'is_reported' => (bool) $model->is_reported,
                'created_at' => $model->created_at?->toIso8601String(),
            ],
            'activity' => [
                'id' => $model->id,
                'description' => $model->description,
                'log_name' => $model->log_name,
                'actor' => $model->causer?->name,
                'subject_type' => $this->humanizeSubject($model->subject_type),
                'created_at' => $model->created_at?->toIso8601String(),
            ],
        };
    }

    private function humanizeSubject(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }

        $basename = class_basename($class);

        return str($basename)->snake(' ')->toString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function sort(array $filters): string
    {
        return in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function direction(array $filters): string
    {
        return ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
