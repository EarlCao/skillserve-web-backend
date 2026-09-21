<?php

namespace App\Modules\ReportsAndModeration\Listeners;

use App\Models\User;
use App\Modules\ReportsAndModeration\Events\ReportRejected;
use App\Modules\ReportsAndModeration\Events\ReportResolved;
use App\Modules\ReportsAndModeration\Notifications\ReportOutcomeNotification;

/** The reporter hears when their report is resolved or rejected. */
class NotifyReporterOfOutcome
{
    public function handle(ReportResolved|ReportRejected $event): void
    {
        $reporter = User::query()->find($event->report->reporter_id);

        if (! $reporter || ! $reporter->isMobileAccount()) {
            return;
        }

        $reporter->notify($event instanceof ReportResolved
            ? new ReportOutcomeNotification(
                $event->report, 'resolved', 'Report resolved',
                'Thank you — our team reviewed your report and took appropriate action.',
            )
            : new ReportOutcomeNotification(
                $event->report, 'rejected', 'Report reviewed',
                "Our team reviewed your report and found no violation. Reason: {$event->reason}",
            ));
    }
}
