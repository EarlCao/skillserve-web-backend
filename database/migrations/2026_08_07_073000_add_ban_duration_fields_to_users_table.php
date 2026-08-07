<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null = permanent (forever) ban — never auto-expires. A value =
            // temporary ban that auto-lifts once the timestamp passes.
            $table->timestamp('banned_until')->nullable()->after('ban_reason');

            // Note recorded by the administrator who lifted the ban.
            $table->string('unban_reason', 500)->nullable()->after('banned_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_until', 'unban_reason']);
        });
    }
};
