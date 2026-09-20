<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly hours a provider publishes on their public profile.
 *
 * One row per weekday (0 = Sunday … 6 = Saturday), enforced by the unique
 * key, because the mobile app edits a single window per day. Times are wall
 * clock in the platform's single timezone, the same basis the booking flow
 * already uses for `scheduled_date`.
 *
 * Purely additive: a provider with no rows is unconstrained, so every
 * existing provider keeps behaving exactly as before and no backfill is
 * needed. Safe to run ahead of the code that reads it; rolling it back
 * discards published schedules only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_availabilities', function (Blueprint $table): void {
            $table->id();
            // Cascades on delete: a schedule has no meaning without its
            // provider profile, which is removed only with the account.
            $table->foreignId('provider_profile_id')
                ->constrained('provider_profiles')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            // One window per provider per weekday. The composite key also
            // serves the discovery filter, which always looks a weekday up
            // within a provider, so no separate day index is needed.
            $table->unique(['provider_profile_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_availabilities');
    }
};
