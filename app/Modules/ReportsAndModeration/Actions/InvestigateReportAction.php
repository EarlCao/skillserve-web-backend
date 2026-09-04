<?php

namespace App\Modules\ReportsAndModeration\Actions;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: assign a report to an investigator and move it into
 * the "investigating" state. An optional opening note is appended to the
 * investigation log.
 */
final class InvestigateReportAction extends BaseAction
{
    public function handle(Report $report, User $actor, ?string $note = null): Report
    {
        if ($report->isTerminal()) {
            throw new ApiException(
                'This report has already been resolved or rejected.',
                422,
                errors: ['status' => ['A resolved or rejected report cannot be investigated.']],
            );
        }

        $notes = $report->investigation_notes ?? [];

        if ($note !== null && trim($note) !== '') {
            $notes[] = $this->note($actor, $note);
        }

        $report->update([
            'status' => 'investigating',
            'investigated_by' => $actor->id,
            'investigated_at' => now(),
            'investigation_notes' => $notes,
        ]);

        return $report;
    }

    /**
     * @return array{note: string, created_at: string, created_by: int}
     */
    private function note(User $actor, string $note): array
    {
        return [
            'note' => trim($note),
            'created_at' => now()->toIso8601String(),
            'created_by' => $actor->id,
        ];
    }
}
