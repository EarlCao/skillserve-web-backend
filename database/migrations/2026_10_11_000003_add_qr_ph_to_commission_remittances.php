<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission payments by PayMongo QR Ph, beside the GCash-reference ones.
 *
 * A provider can now ask for a QR code for exactly what they owe. PayMongo
 * makes a single-use dynamic QR Ph code; the provider scans it with GCash or
 * any QR Ph app, and PayMongo's signed `payment.paid` webhook confirms it —
 * no administrator has to look anything up. The money is SkillServe's own
 * commission, so collecting it does not put SkillServe in the customers'
 * payment path (ADR-021).
 *
 * `method`             gcash_reference (the provider types the reference, an
 *                      administrator confirms) or qrph (PayMongo confirms).
 * `external_id`        PayMongo's Payment Intent id; the webhook resolves the
 *                      claim through it, hence unique.
 * `external_payment_id` PayMongo's payment id once paid.
 * `qr_image`           the QR as PayMongo returns it (a base-64 image), kept
 *                      only while the code can still be paid.
 * `expires_at`         when the code stops working (30 minutes by default).
 * `last_event_id`      the last webhook applied, so a redelivery is a no-op.
 * `failure_reason`     why a QR attempt failed, or why a paid one needs a
 *                      person (an amount that did not match).
 *
 * `status` gains `expired` and `failed` for QR attempts; existing rows keep
 * theirs.
 *
 * ## Data impact and deployment
 *
 * Columns only; `method` is NOT NULL with a default, a metadata-only change
 * on PostgreSQL 11+, and every existing row becomes `gcash_reference`, which
 * is what they are. Additive, so it may run before the code. Rollback drops
 * the columns; QR attempts made since would read as GCash-reference claims,
 * so roll back only before any provider has used QR payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_remittances', function (Blueprint $table): void {
            $table->string('method', 30)->default('gcash_reference')->after('provider_profile_id');
            $table->string('external_id')->nullable()->unique()->after('reference');
            $table->string('external_payment_id')->nullable()->after('external_id');
            $table->text('qr_image')->nullable()->after('proof_mime_type');
            $table->timestamp('expires_at')->nullable()->after('qr_image');
            $table->string('last_event_id')->nullable()->after('expires_at');
            $table->text('failure_reason')->nullable()->after('last_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('commission_remittances', function (Blueprint $table): void {
            $table->dropUnique(['external_id']);
            $table->dropColumn([
                'method', 'external_id', 'external_payment_id', 'qr_image',
                'expires_at', 'last_event_id', 'failure_reason',
            ]);
        });
    }
};
