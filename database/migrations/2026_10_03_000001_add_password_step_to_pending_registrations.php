<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile sign-up now confirms the email first and asks for the password
 * afterwards, for email and Google sign-ups alike:
 *
 *   details -> 6-digit code -> password + confirmation -> account
 *
 * - `password` becomes nullable: it is unknown until the last step. Rows
 *   parked by older app versions still carry one and complete on the code.
 * - `email_verified_at` records that the code was confirmed.
 * - `registration_token_hash` is the SHA-256 of a secret handed only to the
 *   device that started the sign-up; setting the password (or cancelling)
 *   requires it, so knowing the email is not enough.
 * - `google_sub` links the account to the Google identity it started from.
 *
 * ## Data impact, deployment and rollback
 *
 * The table only holds unfinished sign-ups (pruned after 24 hours). Up is
 * additive plus a DROP NOT NULL, a metadata-only change on PostgreSQL, so no
 * existing row changes and it can run before the new code. Rollback first
 * deletes in-flight sign-ups that have no password yet (they could not be
 * completed by the old code anyway) and then restores NOT NULL; users caught
 * mid-sign-up simply start again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_registrations', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            $table->string('google_sub')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('registration_token_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        DB::table('pending_registrations')->whereNull('password')->delete();

        Schema::table('pending_registrations', function (Blueprint $table): void {
            $table->dropColumn(['google_sub', 'email_verified_at', 'registration_token_hash']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
