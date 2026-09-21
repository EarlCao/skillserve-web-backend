<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers' saved providers. The mobile app kept them in memory only, so
 * they vanished on every restart.
 *
 * A new table, so existing data is untouched and nothing is backfilled. The
 * unique pair makes saving idempotent at the database level. Both foreign keys
 * cascade on a hard delete, because a favorite means nothing without either
 * side; accounts are soft-deleted, so a restored account keeps its favorites.
 * The rollback drops the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_profile_id')->constrained('provider_profiles')->cascadeOnDelete();
            $table->timestamps();

            // Leads with user_id, so it also serves "this customer's favorites".
            $table->unique(['user_id', 'provider_profile_id']);
            $table->index('provider_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_providers');
    }
};
