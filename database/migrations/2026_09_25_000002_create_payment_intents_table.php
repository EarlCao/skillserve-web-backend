<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to collect a booking's total through a payment gateway.
 *
 * This table was deliberately not created when the gateway abstraction went in
 * (see ADR-019) because nothing wrote to it. The PayMongo integration does, so
 * it exists now.
 *
 * Why a row at all rather than columns on `bookings`: a customer may abandon a
 * GCash redirect and start again, so a booking can have several attempts, and
 * only one of them succeeds. Keeping them separate also means a failed attempt
 * leaves no trace on the booking itself.
 *
 * ## Data impact
 *
 * New table only; no existing row is read or written and no behaviour changes
 * until the pay endpoint is deployed. Deploy order: may run before or after
 * the application code. Rollback drops the table, losing the audit of payment
 * attempts — the bookings themselves, including `payment_status` and
 * `paid_at`, are unaffected because settlement is recorded there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // Which gateway handled it, so a later provider does not need a
            // second table.
            $table->string('gateway')->default('paymongo');

            // The gateway's own id (PayMongo `pi_...`). Unique so a webhook
            // can resolve exactly one attempt.
            $table->string('external_id')->nullable()->unique();

            // Returned to the client so it can poll the intent; not a secret
            // that grants money movement, but still never logged.
            $table->string('client_key')->nullable();

            // Mirrors PayMongo: awaiting_payment_method, awaiting_next_action,
            // processing, succeeded, failed.
            $table->string('status')->default('awaiting_payment_method');

            // Centavos, as the gateway counts them, alongside the peso amount
            // the booking records — so a mismatch is detectable.
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('PHP');

            // Sent as PayMongo's Idempotency-Key. Unique so a retried request
            // reuses the attempt instead of creating a second one.
            $table->string('idempotency_key')->unique();

            $table->text('redirect_url')->nullable();

            // The last webhook event applied, so a redelivered event is a
            // no-op. PayMongo retries, and double-crediting a booking is the
            // failure this prevents.
            $table->string('last_event_id')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
