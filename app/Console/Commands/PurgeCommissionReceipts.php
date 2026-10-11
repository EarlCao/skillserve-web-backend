<?php

namespace App\Console\Commands;

use App\Modules\Commissions\Services\CommissionRemittanceService;
use Illuminate\Console\Command;

/**
 * Deletes the GCash receipt screenshots providers attached to commission
 * payments once their retention period has passed.
 *
 * A receipt is only needed while the payment is reviewed or questioned. The
 * payment, its reference and the reviewer's decision are all kept; only the
 * image goes. Retention is CommissionRemittanceService::RECEIPT_RETENTION_DAYS.
 */
class PurgeCommissionReceipts extends Command
{
    protected $signature = 'commissions:purge-receipts';

    protected $description = 'Delete commission payment receipts whose retention period has passed';

    public function handle(CommissionRemittanceService $remittances): int
    {
        $purged = $remittances->purgeExpiredReceipts();

        $this->info('Purged '.$purged.' commission payment receipt(s).');

        return self::SUCCESS;
    }
}
