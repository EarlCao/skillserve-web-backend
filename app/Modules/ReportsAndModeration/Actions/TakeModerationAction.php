<?php

namespace App\Modules\ReportsAndModeration\Actions;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Reviews\Actions\HideReviewAction;
use App\Modules\Reviews\Actions\RemoveReviewAction;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Actions\HideServiceAction;
use App\Modules\Services\Models\Service;
use App\Modules\Users\Actions\BanUserAction;
use App\Modules\Users\Actions\SuspendUserAction;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: apply a moderation action to the reported item and
 * record it on the report.
 *
 * The underlying account/content changes reuse the exact Actions used by the
 * User, Service, and Review modules so business rules stay in one place (e.g.
 * "a banned account cannot be suspended"). Authorization for the action is
 * handled by the caller against the report policy AND the target module's own
 * gate/policy.
 */
final class TakeModerationAction extends BaseAction
{
    public function __construct(
        private readonly SuspendUserAction $suspendUserAction,
        private readonly BanUserAction $banUserAction,
        private readonly HideServiceAction $hideServiceAction,
        private readonly HideReviewAction $hideReviewAction,
        private readonly RemoveReviewAction $removeReviewAction,
    ) {}

    /**
     * @param  array{action: string, reason?: string|null, duration?: string|null, days?: int|null, note?: string|null}  $payload
     */
    public function handle(Report $report, User $actor, array $payload): Report
    {
        if ($report->isTerminal()) {
            throw new ApiException(
                'This report has already been resolved or rejected.',
                422,
                errors: ['status' => ['Moderation actions cannot be taken on a resolved or rejected report.']],
            );
        }

        $action = $payload['action'];

        $this->assertApplicable($report, $action);

        $this->apply($report, $actor, $action, $payload);

        $report->update([
            'moderation_action' => $action,
            'action_taken_by' => $actor->id,
            'action_taken_at' => now(),
            'action_note' => isset($payload['note']) && trim((string) $payload['note']) !== '' ? trim((string) $payload['note']) : null,
        ]);

        return $report;
    }

    private function assertApplicable(Report $report, string $action): void
    {
        $allowed = match ($report->reportable_type) {
            User::class => ['warning', 'suspend', 'ban'],
            Service::class => ['hide'],
            Review::class => ['hide', 'remove'],
            Message::class => ['remove'],
            default => [],
        };

        if (! in_array($action, $allowed, true)) {
            throw new ApiException(
                'This moderation action is not applicable to the reported item.',
                422,
                errors: ['action' => ['This moderation action is not applicable to the reported item.']],
            );
        }
    }

    /**
     * @param  array{action: string, reason?: string|null, duration?: string|null, days?: int|null, note?: string|null}  $payload
     */
    private function apply(Report $report, User $actor, string $action, array $payload): void
    {
        $target = $report->reportable;

        match ($action) {
            'warning' => null, // warnings are recorded on the report only
            'suspend' => $this->suspendUserAction->handle($target, $actor, $payload['reason']),
            'ban' => $this->banUserAction->handle($target, $actor, $payload['reason'], $payload['duration'] ?? 'forever', $payload['days'] ?? null),
            'hide' => $this->hide($target, $actor),
            'remove' => $this->remove($target, $actor),
            default => null,
        };
    }

    private function hide(mixed $target, User $actor): void
    {
        if ($target instanceof Service) {
            $this->hideServiceAction->handle($target, true);
        } elseif ($target instanceof Review) {
            $this->hideReviewAction->handle($target, $actor);
        } else {
            $this->notApplicable();
        }
    }

    private function remove(mixed $target, User $actor): void
    {
        if ($target instanceof Review) {
            $this->removeReviewAction->handle($target, $actor);
        } elseif ($target instanceof Message) {
            $target->update([
                'status' => 'removed',
                'removed_by' => $actor->id,
                'removed_at' => now(),
            ]);
        } else {
            $this->notApplicable();
        }
    }

    private function notApplicable(): never
    {
        throw new ApiException(
            'This moderation action is not applicable to the reported item.',
            422,
            errors: ['action' => ['This moderation action is not applicable to the reported item.']],
        );
    }
}
