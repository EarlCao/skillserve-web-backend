<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds mobile sign-ups that have NOT completed email verification yet.
 *
 * Accounts used to be written straight into `users` at registration, so a
 * user who backed out of the OTP screen left the address permanently taken.
 * A registration now lives here until the emailed code is confirmed, and
 * only then is the `users` row (and provider profile) created.
 *
 * Purely additive: no existing table is touched, so the migration is safe
 * to run before the application code that uses it is deployed, and rolling
 * it back only discards in-flight (unverified) sign-ups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table): void {
            $table->id();

            // One in-flight registration per address; a repeat sign-up
            // replaces the previous row.
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('password');

            // Mirrors users.role_id (3 = provider, 4 = customer). No foreign
            // key: the row is transient and must never block a roles change.
            $table->unsignedBigInteger('role_id');

            // Provider-only profile fields, captured at sign-up and applied
            // to provider_profiles when the registration is promoted.
            $table->string('business_name')->nullable();
            $table->string('specialization')->nullable();
            $table->unsignedSmallInteger('experience_years')->default(0);
            $table->text('bio')->nullable();

            // Same column names as `users` so the OTP service treats both
            // subjects identically.
            $table->string('email_otp_hash');
            $table->timestamp('email_otp_expires_at');
            $table->unsignedTinyInteger('email_otp_attempts')->default(0);

            // Housekeeping horizon: rows past this are pruned on the next
            // registration attempt and are never verifiable.
            $table->timestamp('expires_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
