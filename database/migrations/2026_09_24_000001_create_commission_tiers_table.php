<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SkillServe's commission is a percentage of the booking amount, and the rate
 * depends on how large that amount is. This table holds the bands an
 * administrator configures; it replaces the single flat
 * `marketplace.commission_rate` setting, which stays in place as the fallback
 * until every deployment has tiers configured.
 *
 * Ranges are INCLUSIVE at both ends (`min_amount <= amount <= max_amount`) so
 * a configuration written the way the business states it — 0–199.99,
 * 200–499.99, 500–999.99, 1000+ — has no gaps for two-decimal peso amounts.
 * A NULL `max_amount` is the open-ended top band.
 *
 * Data impact: new table only, no existing row is read or written, and no
 * behaviour changes until the calculation starts consuming it. Deploy order:
 * this migration may run before or after the application code, in either
 * order, because nothing yet queries the table. Rollback drops the table; any
 * configured tiers are lost, and commission calculation falls back to the flat
 * rate. No backfill is required — an empty table simply means "no tier
 * matched", which the calculator treats as 0%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_tiers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);

            // Peso bounds, matching bookings.total_price precision.
            $table->decimal('min_amount', 10, 2);
            // NULL = open ended ("1000 and above").
            $table->decimal('max_amount', 10, 2)->nullable();

            // Percentage of the booking amount, e.g. 12.50 for 12.5%.
            $table->decimal('percentage', 5, 2);

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Resolving a tier filters on is_active and orders by min_amount.
            $table->index(['is_active', 'min_amount']);
        });

        // Overlap is rejected by CommissionTierService for every driver. On
        // PostgreSQL the database enforces it too, so a direct SQL write or a
        // race between two administrators cannot leave two active bands
        // claiming the same peso amount. Only active, undeleted rows take
        // part; disabled or deleted bands may overlap freely.
        //
        // SQLite (tests) has no exclusion constraints, hence the driver guard;
        // the service-level check is what the test suite exercises.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE commission_tiers
                ADD CONSTRAINT commission_tiers_no_active_overlap
                EXCLUDE USING gist (numrange(min_amount, max_amount, '[]') WITH &&)
                WHERE (is_active AND deleted_at IS NULL)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_tiers');
    }
};
