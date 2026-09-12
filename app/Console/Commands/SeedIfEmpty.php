<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the database only when it has never been seeded.
 *
 * Render's free tier spins idle services down, and the next request pays the
 * full container cold start — which re-ran `db:seed --force` every time,
 * inserting the entire demo dataset (hundreds of row-by-row writes to Neon)
 * before the HTTP server started listening. That regularly pushed boot past
 * two minutes, so the first login after an idle period timed out in the
 * browser. This command makes re-seeding a no-op: once the always-required
 * seeders have run (roles exist), it exits in milliseconds and `artisan
 * serve` starts immediately.
 */
class SeedIfEmpty extends Command
{
    protected $signature = 'db:seed-if-empty {--fresh : Re-run the seeders even when the database is already seeded}';

    protected $description = 'Seed the database only if it has not been seeded yet';

    public function handle(): int
    {
        if (! $this->option('fresh') && $this->isAlreadySeeded()) {
            $this->info('Database already seeded — skipping (use --fresh to re-run).');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--force' => true]);

        return self::SUCCESS;
    }

    /**
     * Roles are seeded first and are always required (see DatabaseSeeder),
     * so their presence is a reliable "already seeded" marker. A missing
     * table (fresh database, or migrations that never ran) counts as
     * unseeded — the downstream db:seed then surfaces the real error.
     */
    private function isAlreadySeeded(): bool
    {
        try {
            return DB::table('roles')->exists();
        } catch (QueryException) {
            return false;
        }
    }
}
