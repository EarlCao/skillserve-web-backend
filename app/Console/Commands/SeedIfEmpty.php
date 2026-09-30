<?php

namespace App\Console\Commands;

use App\Shared\Enums\AccountRole;
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
 * browser. This command makes re-seeding a no-op: once the super-admin
 * exists, it exits in milliseconds and the server starts immediately.
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
     * Seeded means the super-admin account exists, the one thing a deployment
     * cannot work without. Roles are not a usable marker: the 2026_09_18
     * migration inserts the fixed roles, so a freshly migrated database
     * already has them and used to be skipped, leaving nobody able to sign
     * in. A missing table (migrations never ran) counts as unseeded; the
     * downstream db:seed then surfaces the real error.
     */
    private function isAlreadySeeded(): bool
    {
        try {
            return DB::table('users')->where('role_id', AccountRole::SuperAdmin->value)->exists();
        } catch (QueryException) {
            return false;
        }
    }
}
