<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * At most one open report (pending or investigating) per reporter per subject.
 *
 * The client report endpoint already refuses a second open report, but that
 * check cannot see a concurrent insert, so two simultaneous submits could both
 * land. A partial unique index closes that gap at the database. Closed reports
 * (resolved, rejected) and soft-deleted ones are outside the index, so a person
 * can report the same subject again once the first case is decided.
 *
 * Data impact: none — the index only constrains future writes. It will fail to
 * build if duplicate open reports already exist; production has none, and the
 * demo seeder now skips them. Rollback drops the index.
 *
 * PostgreSQL and SQLite (the test database) both support partial indexes with
 * this syntax.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX reports_one_open_per_subject
                ON reports (reporter_id, reportable_type, reportable_id)
                WHERE status IN ('pending', 'investigating') AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reports_one_open_per_subject');
    }
};
