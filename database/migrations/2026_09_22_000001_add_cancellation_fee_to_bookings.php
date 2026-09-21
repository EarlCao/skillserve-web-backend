<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A confirmed booking cancelled inside the cancellation window (System
 * Settings → Booking) records a late-cancellation fee, owed off-platform like
 * the payment itself.
 *
 * Additive and nullable: existing rows keep NULL (no fee), nothing is
 * backfilled, no lock beyond a metadata change. Rollback drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->decimal('cancellation_fee', 10, 2)->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('cancellation_fee');
        });
    }
};
