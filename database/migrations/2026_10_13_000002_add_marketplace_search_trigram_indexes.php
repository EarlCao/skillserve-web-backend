<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trigram indexes for the marketplace keyword search, which matches
 * `LOWER(column) LIKE '%word%'` (ClientCatalogService::whereContains). A
 * plain B-tree cannot serve a leading wildcard; a `pg_trgm` GIN index on the
 * same LOWER(...) expression can, so the search stays fast as the catalog
 * grows. Today's catalog is small, so nothing changes visibly yet.
 *
 * - Additive only: one extension and ten indexes, no table or data change.
 * - PostgreSQL only (tests run on SQLite, which has no trigram indexes).
 * - Built CONCURRENTLY, outside a transaction, so writes are never locked.
 * - Best effort: if the database refuses the extension or an index, the
 *   failure is logged and skipped — search still works without them, and a
 *   deploy must never stop over an optimisation.
 *
 * Rollback: `down()` drops the indexes (the extension stays; dropping it
 * could break anything else that uses it).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, array{0: string, 1: string}> index name => [table, column] */
    private const INDEXES = [
        'services_title_trgm_idx' => ['services', 'title'],
        'services_description_trgm_idx' => ['services', 'description'],
        'services_location_trgm_idx' => ['services', 'location'],
        'service_categories_name_trgm_idx' => ['service_categories', 'name'],
        'service_subcategories_name_trgm_idx' => ['service_subcategories', 'name'],
        'provider_profiles_business_name_trgm_idx' => ['provider_profiles', 'business_name'],
        'provider_profiles_specialization_trgm_idx' => ['provider_profiles', 'specialization'],
        'provider_profiles_bio_trgm_idx' => ['provider_profiles', 'bio'],
        'provider_profiles_location_trgm_idx' => ['provider_profiles', 'location'],
        'users_name_trgm_idx' => ['users', 'name'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! $this->attempt('CREATE EXTENSION IF NOT EXISTS pg_trgm')) {
            return;
        }

        foreach (self::INDEXES as $index => [$table, $column]) {
            $this->attempt("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$table} USING gin (LOWER({$column}) gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::INDEXES) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    private function attempt(string $sql): bool
    {
        try {
            DB::statement($sql);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Marketplace search index skipped.', ['sql' => $sql, 'error' => $exception->getMessage()]);

            return false;
        }
    }
};
