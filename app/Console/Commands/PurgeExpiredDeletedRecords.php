<?php

namespace App\Console\Commands;

use App\Modules\DataManagement\Services\DataManagementService;
use Illuminate\Console\Command;

/**
 * Permanently removes deleted records (of the purgeable types in
 * config/data-management.php) once their retention period has passed.
 */
class PurgeExpiredDeletedRecords extends Command
{
    protected $signature = 'data-management:purge-expired';

    protected $description = 'Permanently delete purgeable records deleted more than the retention period ago';

    public function handle(DataManagementService $dataManagement): int
    {
        $purged = $dataManagement->purgeExpired();

        $this->info('Purged '.array_sum($purged).' expired deleted record(s).');

        return self::SUCCESS;
    }
}
