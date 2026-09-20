<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a provider is currently taking new bookings.
 *
 * Defaults to true so every existing provider stays bookable; no backfill
 * is required and no existing read path changes until the discovery filter
 * and the booking guard start consulting it. Rolling back drops the flag
 * and restores the previous "always accepting" behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->boolean('is_accepting_bookings')->default(true)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropColumn('is_accepting_bookings');
        });
    }
};
