<?php

namespace App\Modules\ReportsAndModeration\Listeners;

use App\Models\User;
use App\Modules\ReportsAndModeration\Events\ReportActionTaken;
use App\Modules\ReportsAndModeration\Events\ReportInvestigated;
use App\Modules\ReportsAndModeration\Events\ReportNoteAdded;
use App\Modules\ReportsAndModeration\Events\ReportRejected;
use App\Modules\ReportsAndModeration\Events\ReportResolved;

/**
 * Records every report lifecycle transition in the Spatie activity log under
 * the "reports" log name.
 */
class LogReportActivity
{
    public function handle(
        ReportInvestigated|ReportNoteAdded|ReportResolved|ReportRejected|ReportActionTaken $event,
    ): void {
        match (true) {
            $event instanceof ReportInvestigated => $this->log(
                $event->actor, $event->report, ['status' => 'investigating'], 'report_investigated',
            ),
            $event instanceof ReportNoteAdded => $this->log(
                $event->actor, $event->report, ['note' => $event->note], 'report_note_added',
            ),
            $event instanceof ReportResolved => $this->log(
                $event->actor, $event->report, ['status' => 'resolved'], 'report_resolved',
            ),
            $event instanceof ReportRejected => $this->log(
                $event->actor, $event->report, ['status' => 'rejected'], 'report_rejected',
            ),
            $event instanceof ReportActionTaken => $this->log(
                $event->actor, $event->report, ['action' => $event->action], 'report_action_taken',
            ),
        };
    }

    private function log(User $actor, mixed $subject, array $properties, string $description): void
    {
        activity('reports')
            ->causedBy($actor)
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }
}
