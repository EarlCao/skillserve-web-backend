<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's claim that they sent SkillServe its commission by GCash.
 *
 * The provider pays SkillServe's GCash outside the app, then submits the GCash
 * reference number here. Nothing is settled until an administrator finds that
 * reference in SkillServe's GCash history and confirms it, which settles every
 * booking the remittance covers:
 *
 *   pending ──administrator confirms──▶ confirmed  (bookings settled)
 *      └─────administrator rejects───▶ rejected   (provider may submit again)
 *
 * `commission_remittance_bookings` snapshots which outstanding bookings the
 * payment was for, and what each owed, at the moment it was submitted — so a
 * commission that becomes outstanding while the claim waits is never settled
 * by money that was not sent for it.
 *
 * `commission_settlements.commission_remittance_id` links each settlement a
 * confirmation writes back to the remittance that paid for it.
 *
 * ## Constraints
 *
 * Two partial unique indexes back the service's checks against concurrent
 * requests: a provider has at most one pending remittance, and a GCash
 * reference can be confirmed only once. Pending claims may share a reference —
 * otherwise whoever typed someone else's reference first would lock the real
 * payer out — and the reviewer is shown the clash. PostgreSQL and SQLite (the
 * test database) both support this syntax.
 *
 * ## Data impact and deployment
 *
 * Two new, empty tables and one nullable column on commission_settlements —
 * a metadata-only change, no backfill, existing settlements keep a null link.
 * Additive, so it may run before the application code. Rollback drops the
 * column and both tables, losing only remittance claims made since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_remittances', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('provider_profile_id')->constrained('provider_profiles')->cascadeOnDelete();

            // What the covered bookings owed when the claim was made, and what
            // the provider says they sent; the reviewer compares the two with
            // SkillServe's GCash history.
            $table->decimal('amount_due', 10, 2);
            $table->decimal('amount_sent', 10, 2);

            // The GCash reference number, stored without spaces, upper-cased.
            $table->string('reference', 40);

            // Optional receipt screenshot on the private disk.
            $table->string('proof_path')->nullable();
            $table->string('proof_mime_type', 100)->nullable();

            $table->string('status', 20)->default('pending');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // The rejection reason, or the reviewer's note on a confirmation.
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->index('provider_profile_id');
            // The review queue: pending first, oldest first.
            $table->index(['status', 'created_at']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX commission_remittances_one_pending_per_provider
                ON commission_remittances (provider_profile_id)
                WHERE status = 'pending'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX commission_remittances_reference_confirmed
                ON commission_remittances (reference)
                WHERE status = 'confirmed'"
        );

        // Finding the other claims on a reference for the reviewer.
        Schema::table('commission_remittances', function (Blueprint $table): void {
            $table->index('reference');
        });

        Schema::create('commission_remittance_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commission_remittance_id')->constrained('commission_remittances')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            // The commission the booking owed at submission.
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['commission_remittance_id', 'booking_id']);
            $table->index('booking_id');
        });

        Schema::table('commission_settlements', function (Blueprint $table): void {
            $table->foreignId('commission_remittance_id')
                ->nullable()
                ->after('provider_profile_id')
                ->constrained('commission_remittances')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_settlements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commission_remittance_id');
        });

        Schema::dropIfExists('commission_remittance_bookings');
        DB::statement('DROP INDEX IF EXISTS commission_remittances_reference_confirmed');
        DB::statement('DROP INDEX IF EXISTS commission_remittances_one_pending_per_provider');
        Schema::dropIfExists('commission_remittances');
    }
};
