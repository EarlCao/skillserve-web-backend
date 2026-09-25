<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer sends a provider's money.
 *
 * SkillServe is not in the payment path: the customer pays the provider
 * directly and the provider then remits the commission (ADR-021). For a GCash
 * booking that only works if the customer can see the provider's GCash
 * details, which the platform did not store at all.
 *
 * `gcash_name` exists alongside the number because GCash shows the recipient's
 * registered name before a transfer is confirmed — a customer who can compare
 * it against what SkillServe displays can spot a mistyped or swapped number
 * before sending money.
 *
 * ## Privacy
 *
 * These are personal payment details, not public profile fields. They are
 * exposed only to a customer who has an actual booking with that provider,
 * and never through the public catalog.
 *
 * ## Data impact and deployment
 *
 * Two nullable columns, no backfill, no lock beyond a metadata change.
 * Existing providers simply have none until they fill them in, which the
 * booking flow reports rather than failing on. Additive, so it may run before
 * or after the application code. Rollback drops both columns, losing only the
 * stored details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            // Stored normalised as 09XXXXXXXXX (11 digits).
            $table->string('gcash_number', 20)->nullable()->after('website');

            // The registered GCash account name, for the customer to check
            // against what the GCash app shows them.
            $table->string('gcash_name', 120)->nullable()->after('gcash_number');
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropColumn(['gcash_number', 'gcash_name']);
        });
    }
};
