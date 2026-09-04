<?php

namespace App\Modules\ReportsAndModeration\Actions;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: mark a report as rejected when no violation is found.
 */
final class RejectReportAction extends BaseAction
{
    public function handle(Report $report, User $actor, string $reason): Report
    {
        if ($report->isTerminal()) {
            throw new ApiException(
                'This report has already been resolved or rejected.',
                422,
                errors: ['status' => ['A resolved or rejected report cannot be rejected again.']],
            );
        }

        $report->update([
            'status' => 'rejected',
            'rejected_by' => $actor->id,
            'rejected_at' => now(),
            'reject_reason' => $reason,
        ]);

        return $report;
    }
}
