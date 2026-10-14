<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chat before booking: a customer can message a provider about a job before
 * there is a booking. One `inquiries` row per customer–provider pair holds
 * the thread; its messages live in `messages` like a booking chat's, with
 * `inquiry_id` instead of `booking_id`.
 *
 * Additive only — existing messages keep `inquiry_id` null. Rolling back
 * drops the thread table and column; messages written to an inquiry stay in
 * `messages` without a thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table): void {
            $table->id();
            // An account or profile with a conversation is never hard-deleted
            // from under it (Data Management checks this first).
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('provider_profile_id')->constrained('provider_profiles')->restrictOnDelete();
            $table->timestamps();

            // One thread per pair; the leading column also serves customer lookups.
            $table->unique(['customer_id', 'provider_profile_id']);
            $table->index('provider_profile_id');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('inquiry_id')->nullable()->after('booking_id')->constrained('inquiries')->nullOnDelete();
            // booking_id is null on these rows, and Postgres treats nulls as
            // distinct, so inquiry sends need their own idempotency key.
            $table->unique(['inquiry_id', 'sender_id', 'client_idempotency_key'], 'messages_inquiry_sender_idempotency_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_one_thread_check CHECK (num_nonnulls(booking_id, inquiry_id) <= 1)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_one_thread_check');
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique('messages_inquiry_sender_idempotency_unique');
            $table->dropConstrainedForeignId('inquiry_id');
        });

        Schema::dropIfExists('inquiries');
    }
};
