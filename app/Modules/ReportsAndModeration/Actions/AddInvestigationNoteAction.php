<?php

namespace App\Modules\ReportsAndModeration\Actions;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: append an investigator's findings to the report's
 * append-only investigation notes.
 */
final class AddInvestigationNoteAction extends BaseAction
{
    public function handle(Report $report, User $actor, string $note): Report
    {
        if ($report->isTerminal()) {
            throw new ApiException(
                'This report has already been resolved or rejected.',
                422,
                errors: ['status' => ['Notes cannot be added to a resolved or rejected report.']],
            );
        }

        $notes = $report->investigation_notes ?? [];
        $notes[] = [
            'note' => trim($note),
            'created_at' => now()->toIso8601String(),
            'created_by' => $actor->id,
        ];

        $report->update(['investigation_notes' => $notes]);

        return $report;
    }
}
