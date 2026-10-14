<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money the provider must give back when a booking with a confirmed payment
 * (a downpayment or the full amount) is cancelled: the provider returns it
 * with proof, the customer confirms or reports it missing, and an
 * administrator resolves what is left. One per booking.
 *
 * Additive, no backfill — bookings cancelled before this existed are not
 * reopened. Rolling back drops the table; proof screenshots stay on disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            // due → sent → confirmed, or sent → not_received → resolved.
            $table->string('status', 20)->default('due');
            $table->timestamp('due_by');
            // The provider's proof of returning it.
            $table->string('reference', 100)->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            // The customer's answer.
            $table->timestamp('confirmed_at')->nullable();
            $table->text('customer_note')->nullable();
            // When administrators were alerted (overdue or not received).
            $table->timestamp('escalated_at')->nullable();
            // An administrator's decision on a case the parties could not settle.
            $table->string('resolution', 20)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_refunds');
    }
};
