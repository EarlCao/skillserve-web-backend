<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booking must remember the commission it was actually charged, because an
 * administrator may change the tiers afterwards and historical bookings may
 * not move with them.
 *
 * The commission *amount* already has a column — `platform_fee` — so this
 * migration adds only what is missing: the percentage that produced it and
 * the tier it came from. `platform_fee` keeps its meaning, which is why the
 * mobile and admin contracts do not change.
 *
 * Data impact: two nullable columns, no backfill. Existing bookings keep NULL,
 * which reads correctly as "charged before tiers existed" — their
 * `platform_fee` was produced by the flat marketplace.commission_rate setting
 * and is left untouched. `commission_tier_id` nulls itself if the tier is ever
 * hard-deleted, so the booking survives; the rate snapshot is what the money
 * is actually reconciled against.
 *
 * Deploy order: additive and nullable, so it is safe to run before the
 * application code that writes these columns. Rollback drops both columns and
 * loses only the snapshot metadata; `platform_fee` is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // The percentage applied, e.g. 12.50 — snapshotted, never recomputed.
            $table->decimal('commission_rate', 5, 2)->nullable()->after('platform_fee');

            $table->foreignId('commission_tier_id')->nullable()->after('commission_rate')
                ->constrained('commission_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commission_tier_id');
            $table->dropColumn('commission_rate');
        });
    }
};
