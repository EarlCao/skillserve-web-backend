<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mobile booking form asks where the job happens and which number to
 * call. Both were collected and discarded because `bookings` had nowhere to
 * keep them, so a provider could not tell where to go.
 *
 * Purely additive and nullable: existing rows keep NULL, nothing is
 * backfilled, and no index or default changes, so the columns can ship
 * before the API that writes them. The rollback drops both columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('service_address')->nullable()->after('provider_notes');
            $table->string('contact_phone', 32)->nullable()->after('service_address');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['service_address', 'contact_phone']);
        });
    }
};
