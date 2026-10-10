<?php

namespace App\Modules\Dashboard\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Commissions\Services\CommissionTierService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationRequest;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Services\Models\Service;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class DashboardService
{
    private const PLATFORM_USER_TYPES = ['customer', 'provider'];

    private const BOOKING_STATUSES = ['pending', 'confirmed', 'active', 'completed', 'cancelled', 'disputed'];

    public function __construct(
        private readonly CommissionLedger $ledger,
        private readonly CommissionTierService $tiers,
        private readonly SettingsService $settings,
    ) {}

    /**
     * What is waiting for an administrator, for the sidebar's count badges:
     * support tickets nobody has answered yet and reports nobody has picked
     * up. Each count is null for a viewer who may not open that list.
     *
     * @return array{open_support_tickets: ?int, pending_reports: ?int}
     */
    public function attention(User $viewer): array
    {
        return [
            'open_support_tickets' => $viewer->can('viewAny', SupportTicket::class)
                ? SupportTicket::query()->where('status', 'open')->count()
                : null,
            'pending_reports' => $viewer->can('viewAny', Report::class)
                ? Report::query()->where('status', 'pending')->count()
                : null,
        ];
    }

    /**
     * Build the dashboard read model from the existing module tables. The
     * commission block is revenue data, so it is only included for viewers
     * who may read commissions.
     *
     * @return array<string, mixed>
     */
    public function summary(User $viewer): array
    {
        $bookingSummary = $this->bookingSummary();

        return [
            ...($viewer->can('viewAny', CommissionTier::class) ? ['commission_summary' => $this->commissionSummary()] : []),
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
        $users = User::query()->mobileAccounts();

        return [
            'total_clients' => (clone $users)->customers()->count(),
            'total_providers' => (clone $users)->providers()->count(),
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

    /**
     * What SkillServe has collected in commission, as pesos and as a share of
     * the bookings it was collected on, plus the rates currently in force.
     *
     * `collected_rate` is the effective percentage: settled commission over
     * the value of the settled bookings. It differs from any single tier's
     * rate when bookings fell into different bands, or were charged before a
     * rate change (each booking keeps its own snapshot).
     *
     * @return array<string, mixed>
     */
    private function commissionSummary(): array
    {
        $totals = $this->ledger->totals([]);
        $collectedValue = (float) Booking::query()
            ->where('commission_status', CommissionLedger::SETTLED)
            ->sum('total_price');
        $tiers = $this->tiers->activeTiers()->sortBy('min_amount')->values();

        return [
            'collected' => $totals['settled'],
            'collected_booking_value' => $this->money($collectedValue),
            'collected_rate' => $this->money($collectedValue > 0 ? (float) $totals['settled'] / $collectedValue * 100 : 0),
            'outstanding' => $totals['outstanding'],
            'waived' => $totals['waived'],
            // tiers: the bands below decide the rate. fallback: no band is
            // active, so every booking is charged the flat settings rate.
            'rate_source' => $tiers->isEmpty() ? 'fallback' : 'tiers',
            'fallback_rate' => $this->money((float) $this->settings->value('marketplace', 'commission_rate')),
            'tiers' => $tiers->map(fn (CommissionTier $tier): array => [
                'id' => $tier->id,
                'name' => $tier->name,
                'min_amount' => $tier->min_amount,
                'max_amount' => $tier->max_amount,
                'percentage' => $tier->percentage,
            ])->all(),
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
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
            $query->mobileAccounts();
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
                'active' => User::query()->mobileAccounts()->where('status', 'active')->count(),
                'suspended' => User::query()->mobileAccounts()->where('status', 'suspended')->count(),
                'banned' => User::query()->mobileAccounts()->where('status', 'banned')->count(),
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
