<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proof of a GCash payment: the customer sends what they paid — a
 * downpayment or the rest — with a screenshot, and the provider confirms or
 * rejects it. Confirmed amounts add up in `bookings.amount_paid`.
 *
 * Additive. Bookings already paid (or refunded) are backfilled with their
 * total, so every booking's balance reads correctly; the rest start at 0.
 * Rolling back drops the table and column; screenshot files stay on disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payment_proofs', function (Blueprint $table): void {
            $table->id();
            // A proof belongs to its booking and goes with it.
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('reference', 100)->nullable();
            // Private `payment_proofs` disk; never exposed, streamed after authorisation.
            $table->string('image_path');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->decimal('amount_paid', 10, 2)->default(0)->after('payment_status');
        });

        DB::table('bookings')
            ->whereIn('payment_status', ['paid', 'partially_refunded', 'refunded'])
            ->update(['amount_paid' => DB::raw('total_price')]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('amount_paid');
        });

        Schema::dropIfExists('booking_payment_proofs');
    }
};
