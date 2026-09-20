<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account settings for the mobile app.
 *
 * Preferences used to live only in the device's SharedPreferences, so they
 * were lost on reinstall and never followed the account to a second device.
 * They are also read server-side — notification categories gate delivery,
 * and `private_profile` hides a provider from public discovery — so they
 * have to be stored here rather than on the client.
 *
 * One row per user, created on first read. The foreign key cascades on
 * delete because the row has no meaning without its account; users are
 * soft-deleted in normal operation, so this only fires on a hard delete
 * (for example an abandoned registration being purged).
 *
 * Additive: a new table with defaults matching the app's previous
 * behaviour, so it is safe to run ahead of the code that reads it, and
 * rolling it back only discards saved preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Notification categories. Defaults match the app's shipped
            // defaults so existing users see no change.
            $table->boolean('booking_notifications')->default(true);
            $table->boolean('service_notifications')->default(true);
            $table->boolean('message_notifications')->default(true);
            $table->boolean('announcement_notifications')->default(true);

            // Privacy.
            $table->boolean('private_profile')->default(false);
            $table->boolean('activity_personalization')->default(true);

            // Application.
            $table->boolean('reduce_motion')->default(false);
            $table->string('theme', 10)->default('system');

            $table->timestamps();
        });

        // Keep the enum honest at the database level, not only in validation.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE client_preferences ADD CONSTRAINT client_preferences_theme_check CHECK (theme IN ('light', 'dark', 'system'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_preferences');
    }
};
