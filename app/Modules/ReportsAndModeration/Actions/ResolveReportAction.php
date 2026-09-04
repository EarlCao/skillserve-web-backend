<?php

namespace App\Modules\ReportsAndModeration\Actions;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: mark a report as resolved once the appropriate
 * administrative action has been completed.
 */
final class ResolveReportAction extends BaseAction
{
    public function handle(Report $report, User $actor, string $resolutionNote): Report
    {
        if ($report->isTerminal()) {
            throw new ApiException(
                'This report has already been resolved or rejected.',
                422,
                errors: ['status' => ['A resolved or rejected report cannot be resolved again.']],
            );
        }

        $report->update([
            'status' => 'resolved',
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'resolution_note' => $resolutionNote,
        ]);

        return $report;
    }
}
