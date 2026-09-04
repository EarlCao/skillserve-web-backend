<?php

namespace App\Modules\ReportsAndModeration\Services;

use App\Models\User;
use App\Modules\ReportsAndModeration\Actions\AddInvestigationNoteAction;
use App\Modules\ReportsAndModeration\Actions\InvestigateReportAction;
use App\Modules\ReportsAndModeration\Actions\RejectReportAction;
use App\Modules\ReportsAndModeration\Actions\ResolveReportAction;
use App\Modules\ReportsAndModeration\Actions\TakeModerationAction;
use App\Modules\ReportsAndModeration\Events\ReportActionTaken;
use App\Modules\ReportsAndModeration\Events\ReportInvestigated;
use App\Modules\ReportsAndModeration\Events\ReportNoteAdded;
use App\Modules\ReportsAndModeration\Events\ReportRejected;
use App\Modules\ReportsAndModeration\Events\ReportResolved;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Reviews\Events\ReviewHidden;
use App\Modules\Reviews\Events\ReviewRemoved;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Events\ServiceHidden;
use App\Modules\Services\Models\Service;
use App\Modules\Users\Events\UserBanned;
use App\Modules\Users\Events\UserSuspended;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Orchestrates the report lifecycle: listing (search/filter/sort/paginate),
 * investigation, investigation notes, resolution, rejection, and moderation
 * actions. Controllers stay thin.
 */
class ReportService extends BaseService
{
    private const SORTABLE = ['created_at', 'updated_at', 'status'];

    /**
     * Friendly report type keys accepted by the API, mapped to model classes.
     */
    private const TYPES = [
        'user' => User::class,
        'service' => Service::class,
        'review' => Review::class,
        'message' => Message::class,
    ];

    public function __construct(
        private readonly InvestigateReportAction $investigateReportAction,
        private readonly AddInvestigationNoteAction $addInvestigationNoteAction,
        private readonly ResolveReportAction $resolveReportAction,
        private readonly RejectReportAction $rejectReportAction,
        private readonly TakeModerationAction $takeModerationAction,
    ) {}

    /**
     * @param  array{search?: string, type?: string, status?: string, reason?: string, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = Report::query()->with($this->relations());

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($q) use ($term, $search): void {
                $q->whereRaw('LOWER(reason) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereHas('reporter', function ($q2) use ($term): void {
                        $q2->whereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
                    });

                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        if ($type = trim((string) ($filters['type'] ?? ''))) {
            $class = self::TYPES[$type] ?? null;

            if ($class !== null) {
                $query->where('reportable_type', $class);
            }
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if ($reason = trim((string) ($filters['reason'] ?? ''))) {
            $query->where('reason', $reason);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters));
    }

    /**
     * Load a single report with its reportable target and actor stamps.
     */
    public function show(Report $report): Report
    {
        return $report->load($this->relations());
    }

    /**
     * Assign the report to an investigator and start the investigation.
     */
    public function investigate(Report $report, User $actor, ?string $note = null): Report
    {
        return $this->transaction(function () use ($report, $actor, $note): Report {
            $this->investigateReportAction->handle($report, $actor, $note);

            $report->load($this->relations());

            event(new ReportInvestigated(report: $report, actor: $actor, note: $note));

            return $report;
        });
    }

    /**
     * Append an investigation note to a report.
     */
    public function addNote(Report $report, User $actor, string $note): Report
    {
        return $this->transaction(function () use ($report, $actor, $note): Report {
            $this->addInvestigationNoteAction->handle($report, $actor, $note);

            $report->load($this->relations());

            event(new ReportNoteAdded(report: $report, actor: $actor, note: $note));

            return $report;
        });
    }

    /**
     * Mark a report as resolved.
     */
    public function resolve(Report $report, User $actor, string $resolutionNote): Report
    {
        return $this->transaction(function () use ($report, $actor, $resolutionNote): Report {
            $this->resolveReportAction->handle($report, $actor, $resolutionNote);

            $report->load($this->relations());

            event(new ReportResolved(report: $report, actor: $actor, resolutionNote: $resolutionNote));

            return $report;
        });
    }

    /**
     * Mark a report as rejected (no violation found).
     */
    public function reject(Report $report, User $actor, string $reason): Report
    {
        return $this->transaction(function () use ($report, $actor, $reason): Report {
            $this->rejectReportAction->handle($report, $actor, $reason);

            $report->load($this->relations());

            event(new ReportRejected(report: $report, actor: $actor, reason: $reason));

            return $report;
        });
    }

    /**
     * Apply a moderation action to the reported item and record it.
     *
     * @param  array{action: string, reason?: string|null, duration?: string|null, days?: int|null, note?: string|null}  $payload
     */
    public function takeAction(Report $report, User $actor, array $payload): Report
    {
        return $this->transaction(function () use ($report, $actor, $payload): Report {
            if (! $report->reportable) {
                throw new ApiException(
                    'The reported item is no longer available.',
                    422,
                    errors: ['reportable' => ['The reported item is no longer available.']],
                );
            }

            $this->assertActorMayModerate($actor, $report, $payload['action']);

            $this->takeModerationAction->handle($report, $actor, $payload);

            // Keep the target module's own audit/log/notification side effects
            // consistent with moderating directly from that module.
            $this->dispatchTargetEvent($report, $actor, $payload['action'], $payload);

            $report->load($this->relations());

            event(new ReportActionTaken(report: $report, actor: $actor, action: $payload['action']));

            return $report;
        });
    }

    /**
     * Dispatch the target module's domain event for the applied action so its
     * listeners (activity log, ban/unban emails, etc.) still fire when a
     * moderation action is taken from the Reports module.
     *
     * @param  array{action: string, reason?: string|null}  $payload
     */
    private function dispatchTargetEvent(Report $report, User $actor, string $action, array $payload): void
    {
        $target = $report->reportable;

        match ($action) {
            'suspend' => event(new UserSuspended(user: $target, actor: $actor, reason: $payload['reason'])),
            'ban' => event(new UserBanned(user: $target, actor: $actor, reason: $payload['reason'])),
            'hide' => match ($report->reportable_type) {
                Service::class => event(new ServiceHidden(service: $target, actor: $actor, isHidden: true)),
                Review::class => event(new ReviewHidden(review: $target, actor: $actor)),
                default => null,
            },
            'remove' => $report->reportable_type === Review::class
                ? event(new ReviewRemoved(review: $target, actor: $actor))
                : null,
            default => null,
        };
    }

    /**
     * Moderation actions mutate targets owned by other modules, so the actor
     * must also satisfy the target module's own gate/policy. This prevents the
     * reports module from being used to bypass User/Service/Review
     * authorization (e.g. suspending a user with only "view reports").
     *
     * @throws ApiException when the actor lacks the underlying permission.
     */
    private function assertActorMayModerate(User $actor, Report $report, string $action): void
    {
        $target = $report->reportable;

        $checks = match ($report->reportable_type) {
            User::class => [
                'suspend' => ['suspend users', User::class],
                'ban' => ['ban users', User::class],
            ],
            Service::class => [
                'hide' => ['hide', $target],
            ],
            Review::class => [
                'hide' => ['hide', $target],
                'remove' => ['delete', $target],
            ],
            Message::class => [],
            default => [],
        };

        $check = $checks[$action] ?? null;

        if ($check === null) {
            return;
        }

        [$ability, $argument] = $check;

        if (! $actor->can($ability, $argument)) {
            throw new ApiException(
                'You are not authorized to perform this moderation action.',
                403,
                errors: ['action' => ['You do not have permission to perform this moderation action.']],
            );
        }
    }

    /**
     * Eager-load the relations the resource surfaces, including the nested
     * reportable target relations (per concrete type) to avoid N+1.
     *
     * @return array<int, mixed>
     */
    private function relations(): array
    {
        return [
            'reportable' => fn ($morph) => $morph->morphWith([
                User::class => [],
                Service::class => ['provider:id,user_id,business_name', 'provider.user:id,name,email'],
                Review::class => ['reviewer:id,name,email', 'provider:id,user_id,business_name', 'provider.user:id,name,email', 'service:id,title'],
                Message::class => ['sender:id,name,email', 'receiver:id,name,email'],
            ]),
            'reporter:id,name,email',
            'investigatedBy:id,name',
            'resolvedBy:id,name',
            'rejectedBy:id,name',
            'actionTakenBy:id,name',
        ];
    }

    /**
     * Clamp the requested page size between 1 and 100.
     *
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
