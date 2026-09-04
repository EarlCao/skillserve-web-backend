<?php

namespace App\Modules\ReportsAndModeration\Events;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Report;

class ReportRejected
{
    public function __construct(
        public readonly Report $report,
        public readonly User $actor,
        public readonly string $reason,
    ) {}
}
