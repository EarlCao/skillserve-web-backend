<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Philippine Standard Geographic Code (PSGC): every region, province,
 * city/municipality and barangay, for the address pickers.
 *
 * Reference data only. The rows are not inserted here but by
 * `php artisan locations:import`, which `deploy/render/start.sh` runs on start:
 * loading ~44k rows inside a migration would also run in every test (the test
 * database is migrated per test), multiplying the suite's run time.
 *
 * `parent_code` is deliberately not a foreign key: the import inserts in
 * chunks without ordering guarantees, and the data is replaced wholesale when
 * PSA publishes a new release, never edited row by row.
 *
 * ## Data impact, deployment and rollback
 *
 * A new table; nothing existing changes. Deploy with the code that reads it.
 * Rolling back drops the table; the import refills it on the next start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ph_locations', function (Blueprint $table): void {
            $table->char('code', 9)->primary();
            $table->string('name', 120);
            // region, province, city, municipality or barangay
            $table->string('level', 16)->index();
            // The next level up: a province's region, a city's province (or
            // its region when it has none, as in NCR), a barangay's city or
            // municipality. Null for regions.
            $table->char('parent_code', 9)->nullable();
            $table->char('region_code', 9)->index();

            $table->index(['parent_code', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ph_locations');
    }
};
