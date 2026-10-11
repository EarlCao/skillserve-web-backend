<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own GCash "Receive via QR" code, as the text it encodes.
 *
 * The app reads the provider's QR image on the phone and sends the QR Ph
 * string it contains. For each GCash booking the API writes a copy with the
 * booking's exact amount in it, so the customer scans it and pays the provider
 * directly — SkillServe still never touches the money (ADR-021).
 *
 * Stored as text, not as the image: it is what a copy with an amount is built
 * from, and it is a few hundred characters at most. Like the GCash number it
 * is a personal payment detail, shown only to a customer with an unpaid GCash
 * booking with this provider.
 *
 * ## Data impact and deployment
 *
 * One nullable column, no backfill, a metadata-only change. Existing
 * providers simply have no QR until they add one, and the booking falls back
 * to the number and name. Additive, so it may run before the code. Rollback
 * drops the column, losing only the saved codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->text('gcash_qr_payload')->nullable()->after('gcash_name');
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropColumn('gcash_qr_payload');
        });
    }
};
