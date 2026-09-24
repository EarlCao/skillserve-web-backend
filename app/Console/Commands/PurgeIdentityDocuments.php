<?php

namespace App\Console\Commands;

use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\IdentityVerification\Services\IdentityVerificationService;
use Illuminate\Console\Command;

/**
 * Deletes National ID images once their retention period has passed.
 *
 * The images are only needed while a submission is under review or under
 * dispute. The verification record, its decision and its history are all kept;
 * only the photographs go. Retention is set in System Settings → Identity.
 */
class PurgeIdentityDocuments extends Command
{
    protected $signature = 'identity:purge-documents';

    protected $description = 'Delete National ID images whose retention period has passed';

    public function handle(IdentityVerificationService $verification): int
    {
        $purged = 0;

        IdentityVerification::query()
            ->whereNotNull('documents_purge_after')
            ->where('documents_purge_after', '<=', now())
            ->whereHas('documents')
            ->chunkById(100, function ($records) use ($verification, &$purged): void {
                foreach ($records as $record) {
                    $verification->deleteDocumentsOf($record);
                    $record->update(['documents_purge_after' => null]);
                    $purged++;
                }
            });

        $this->info('Purged National ID images for '.$purged.' verification(s).');

        return self::SUCCESS;
    }
}
