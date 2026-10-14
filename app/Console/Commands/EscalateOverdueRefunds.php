<?php

namespace App\Console\Commands;

use App\Modules\Bookings\Services\BookingRefundService;
use Illuminate\Console\Command;

class EscalateOverdueRefunds extends Command
{
    protected $signature = 'bookings:escalate-overdue-refunds';

    protected $description = 'Alert administrators and providers about payments not returned by their deadline';

    public function handle(BookingRefundService $refunds): int
    {
        $this->info($refunds->escalateOverdue().' overdue refund(s) escalated.');

        return self::SUCCESS;
    }
}
