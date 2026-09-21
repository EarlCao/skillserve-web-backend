<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer can now move a pending or confirmed booking to a new time, which
 * sends an accepted booking back to the provider for approval. The stamp lets
 * the provider tell a rescheduled request from a new one.
 *
 * Purely additive and nullable: existing rows keep NULL (never rescheduled),
 * nothing is backfilled, and no index or default changes, so the column can
 * ship before the API that writes it. The rollback drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('rescheduled_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('rescheduled_at');
        });
    }
};
