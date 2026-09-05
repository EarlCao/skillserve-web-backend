<?php

namespace App\Modules\Dashboard\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationRequest;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class DashboardService
{
    private const PLATFORM_USER_TYPES = ['customer', 'provider'];

    private const BOOKING_STATUSES = ['pending', 'confirmed', 'active', 'completed', 'cancelled', 'disputed'];

    /**
     * Build the dashboard read model from the existing module tables.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $bookingSummary = $this->bookingSummary();

        return [
            'user_summary' => $this->userSummary(),
            'service_summary' => $this->serviceSummary(),
            'booking_summary' => $bookingSummary,
            'verification_summary' => $this->verificationSummary(),
            'reports_summary' => $this->reportsSummary(),
            'recent_activities' => $this->recentActivities(),
            'analytics' => $this->analytics($bookingSummary),
        ];
    }

    /** @return array<string, int> */
    private function userSummary(): array
    {
        $users = User::query()
            ->whereDoesntHave('roles')
            ->whereIn('user_type', self::PLATFORM_USER_TYPES);

        return [
            'total_clients' => (clone $users)->where('user_type', 'customer')->count(),
            'total_providers' => (clone $users)->where('user_type', 'provider')->count(),
            'active_users' => (clone $users)->where('status', 'active')->count(),
            'suspended_users' => (clone $users)->where('status', 'suspended')->count(),
        ];
    }

    /** @return array<string, int> */
    private function serviceSummary(): array
    {
        return [
            'total_services' => Service::query()->count(),
            'approved_services' => Service::query()->where('approval_status', 'approved')->count(),
            'pending_services' => Service::query()->where('approval_status', 'pending')->count(),
            'reported_services' => Report::query()
                ->where('reportable_type', Service::class)
                ->distinct('reportable_id')
                ->count('reportable_id'),
        ];
    }

    /** @return array<string, int> */
    private function bookingSummary(): array
    {
        $counts = Booking::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->whereIn('status', self::BOOKING_STATUSES)
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return collect(self::BOOKING_STATUSES)
            ->mapWithKeys(fn (string $status): array => [$status => $counts[$status] ?? 0])
            ->all();
    }

    /** @return array<string, int> */
    private function verificationSummary(): array
    {
        $counts = VerificationRequest::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->whereIn('status', ['pending', 'approved', 'rejected', 'additional_info_required'])
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return [
            'pending' => $counts['pending'] ?? 0,
            'approved' => $counts['approved'] ?? 0,
            'rejected' => $counts['rejected'] ?? 0,
            'additional_info_required' => $counts['additional_info_required'] ?? 0,
        ];
    }

    /** @return array<string, int> */
    private function reportsSummary(): array
    {
        $counts = Report::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->whereIn('status', ['pending', 'investigating', 'resolved', 'rejected'])
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return [
            'pending' => $counts['pending'] ?? 0,
            'investigating' => $counts['investigating'] ?? 0,
            'resolved' => $counts['resolved'] ?? 0,
            'rejected' => $counts['rejected'] ?? 0,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function recentActivities(): array
    {
        return Activity::query()
            ->with('causer:id,name')
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'description' => $activity->description,
                'log_name' => $activity->log_name,
                'causer' => $activity->causer ? [
                    'id' => $activity->causer->id,
                    'name' => $activity->causer->name,
                ] : null,
                'created_at' => $activity->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    /** @param  array<string, int>  $bookingSummary */
    private function analytics(array $bookingSummary): array
    {
        $start = Carbon::now()->subMonths(5)->startOfMonth();
        $end = Carbon::now()->endOfMonth();
        $users = $this->monthlyCounts(User::class, function ($query): void {
            $query->whereDoesntHave('roles')->whereIn('user_type', self::PLATFORM_USER_TYPES);
        }, $start, $end);
        $providers = $this->monthlyCounts(ProviderProfile::class, null, $start, $end);
        $services = $this->monthlyCounts(Service::class, null, $start, $end);
        $bookings = $this->monthlyCounts(Booking::class, null, $start, $end);

        $months = collect(range(5, 0))->map(function (int $monthsAgo) use ($users, $providers, $services, $bookings): array {
            $month = Carbon::now()->subMonths($monthsAgo)->startOfMonth();
            $key = $month->format('Y-m');

            return [
                'label' => $month->format('M'),
                'month' => $key,
                'users' => $users[$key] ?? 0,
                'providers' => $providers[$key] ?? 0,
                'services' => $services[$key] ?? 0,
                'bookings' => $bookings[$key] ?? 0,
            ];
        })->values()->all();

        return [
            'monthly_activity' => $months,
            'booking_statuses' => $bookingSummary,
            'user_statuses' => [
                'active' => User::query()->whereDoesntHave('roles')->whereIn('user_type', self::PLATFORM_USER_TYPES)->where('status', 'active')->count(),
                'suspended' => User::query()->whereDoesntHave('roles')->whereIn('user_type', self::PLATFORM_USER_TYPES)->where('status', 'suspended')->count(),
                'banned' => User::query()->whereDoesntHave('roles')->whereIn('user_type', self::PLATFORM_USER_TYPES)->where('status', 'banned')->count(),
            ],
        ];
    }

    /**
     * Return monthly creation counts in one grouped query per model.
     * SQLite is supported for the feature test suite; production uses PostgreSQL.
     *
     * @param  class-string  $modelClass
     * @param  (\Closure(mixed): void)|null  $scope
     * @return array<string, int>
     */
    private function monthlyCounts(string $modelClass, ?\Closure $scope, Carbon $start, Carbon $end): array
    {
        $monthExpression = DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(created_at, 'YYYY-MM')"
            : "strftime('%Y-%m', created_at)";
        $query = $modelClass::query()->whereBetween('created_at', [$start, $end]);

        if ($scope) {
            $scope($query);
        }

        return $query
            ->selectRaw("{$monthExpression} as month, COUNT(*) as aggregate")
            ->groupByRaw($monthExpression)
            ->pluck('aggregate', 'month')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}
