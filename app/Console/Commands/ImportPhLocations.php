<?php

namespace App\Console\Commands;

use App\Modules\Locations\Models\PhLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the Philippine Standard Geographic Code into `ph_locations` from the
 * snapshot in `database/data/ph_locations.json.gz` (PSA PSGC, via
 * psgc.gitlab.io; the file names its source and download date).
 *
 * Runs on every start: when the table already holds data it exits at once,
 * so only a fresh database pays for the ~44k-row load. `--fresh` replaces the
 * data, which is how a newer PSA release is applied after the snapshot file
 * is updated.
 */
class ImportPhLocations extends Command
{
    protected $signature = 'locations:import
        {--fresh : Replace the existing locations}
        {--path= : Snapshot to load (defaults to database/data/ph_locations.json.gz)}';

    protected $description = 'Load the Philippine region/province/city/barangay list (PSGC)';

    private const CHUNK = 1000;

    public function handle(): int
    {
        if (! $this->option('fresh') && PhLocation::query()->exists()) {
            $this->info('Philippine locations already loaded — skipping (use --fresh to replace).');

            return self::SUCCESS;
        }

        $snapshot = $this->readSnapshot($this->option('path') ?: database_path('data/ph_locations.json.gz'));

        DB::transaction(function () use ($snapshot): void {
            PhLocation::query()->delete();

            foreach (array_chunk($snapshot['rows'], self::CHUNK) as $chunk) {
                PhLocation::query()->insert(array_map(fn (array $row): array => [
                    'code' => $row[0],
                    'name' => $row[1],
                    'level' => $row[2],
                    'parent_code' => $row[3],
                    'region_code' => $row[4],
                ], $chunk));
            }
        });

        $this->info(sprintf('Loaded %s Philippine locations (%s).', number_format(count($snapshot['rows'])), $snapshot['source']));

        return self::SUCCESS;
    }

    /** @return array{source: string, rows: array<int, array{0: string, 1: string, 2: string, 3: string|null, 4: string}>} */
    private function readSnapshot(string $path): array
    {
        $raw = is_file($path) ? @gzdecode((string) file_get_contents($path)) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        if (! is_array($data) || ! isset($data['rows']) || ! is_array($data['rows'])) {
            throw new RuntimeException("The locations snapshot at {$path} is missing or unreadable.");
        }

        return ['source' => (string) ($data['source'] ?? 'unknown source'), 'rows' => $data['rows']];
    }
}
