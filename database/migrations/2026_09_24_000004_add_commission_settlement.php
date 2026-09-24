<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks whether SkillServe has actually received the commission on a booking.
 *
 * The commission is inclusive, so on an on-hand job the provider collects the
 * whole advertised price in cash — SkillServe's share included — and then owes
 * it back. That debt is what these columns record, and what stops a provider
 * taking on new work until they settle.
 *
 * `bookings.commission_status`:
 *   pending     — the job has not been paid for, so nothing is owed yet
 *   outstanding — the provider has been paid and owes SkillServe its share
 *   settled     — the share has been received (see commission_settlements)
 *   waived      — an administrator wrote the debt off, with a reason
 *   voided      — the booking was cancelled or fully refunded; no share is due
 *
 * Data impact: `commission_status` is NOT NULL with a default, which on
 * PostgreSQL 11+ is a metadata-only change — existing rows are not rewritten
 * and no long lock is taken. Every existing booking therefore becomes
 * `pending`, which is the correct reading: no commission was ever collected
 * for them, and because only bookings paid *after* this deploy move to
 * `outstanding`, no provider is retroactively put in debt. The settlements
 * table is new and empty.
 *
 * Deploy order: run before the application code. Rollback drops the table and
 * the two columns; the commission amounts on `bookings` are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('commission_status')->default('pending')->after('commission_tier_id');
            $table->timestamp('commission_settled_at')->nullable()->after('commission_status');

            // "What does this provider still owe?" is the hot query.
            $table->index(['provider_id', 'commission_status']);
        });

        Schema::create('commission_settlements', function (Blueprint $table): void {
            $table->id();

            // One booking settles once; a second attempt is refused in the
            // service and by this constraint.
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();

            // Denormalised so a provider's settlement history survives and
            // stays queryable even if the booking is later archived.
            $table->foreignId('provider_profile_id')->constrained('provider_profiles')->cascadeOnDelete();

            $table->decimal('amount', 10, 2);

            // How SkillServe received it: gcash, bank_transfer, cash, offset…
            $table->string('method');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            // The administrator who recorded it; kept even if they leave.
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at');

            $table->timestamps();

            $table->index('provider_profile_id');
            $table->index('settled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settlements');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['provider_id', 'commission_status']);
            $table->dropColumn(['commission_status', 'commission_settled_at']);
        });
    }
};
