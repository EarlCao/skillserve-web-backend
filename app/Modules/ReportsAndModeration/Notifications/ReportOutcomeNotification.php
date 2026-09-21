<?php

namespace App\Modules\ReportsAndModeration\Notifications;

use App\Modules\ReportsAndModeration\Models\Report;
use App\Shared\Notifications\BaseNotification;

/**
 * Tells the person who filed a report how it ended. It never reveals the
 * moderation action taken against the other party.
 */
class ReportOutcomeNotification extends BaseNotification
{
    public function __construct(
        private readonly Report $report,
        private readonly string $action,
        private readonly string $title,
        private readonly string $message,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report_update',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'report_id' => $this->report->id,
        ];
    }
}
