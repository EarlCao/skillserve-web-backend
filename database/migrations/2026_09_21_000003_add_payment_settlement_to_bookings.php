<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement is recorded by hand — no payment provider is called — so each
 * booking now keeps who marked it paid and when, and any refund recorded
 * against it.
 *
 * Additive only. Every column is nullable except `refunded_amount`, whose
 * default of 0 is a metadata-only change on PostgreSQL 11+, so existing rows
 * are not rewritten and no long lock is taken. Nothing is backfilled: rows
 * that are already `paid` (demo data only; the API never wrote it) keep a NULL
 * `paid_at`. `payment_recorded_by` nulls itself if that account is deleted,
 * so the booking survives. The rollback drops the five columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable()->after('payment_reference');
            $table->foreignId('payment_recorded_by')->nullable()->after('paid_at')
                ->constrained('users')->nullOnDelete();
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('payment_recorded_by');
            $table->timestamp('refunded_at')->nullable()->after('refunded_amount');
            $table->text('refund_reason')->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_recorded_by');
            $table->dropColumn(['paid_at', 'refunded_amount', 'refunded_at', 'refund_reason']);
        });
    }
};
