<?php

namespace App\Modules\ReportsAndModeration\Events;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Report;

/** A user filed a report from the app. */
class ReportSubmitted
{
    public function __construct(
        public readonly Report $report,
        public readonly User $actor,
    ) {}
}
