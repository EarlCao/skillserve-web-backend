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
            // Names are stored separately (form uses First/Last) while `name`
            // stays as the combined display value consumed by the auth flow.
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');

            // Activation status — inactive accounts are rejected at login.
            $table->string('status')->default('active')->after('password');

            // Last successful login timestamp (surfaced in the admin list).
            $table->timestamp('last_login_at')->nullable()->after('status');

            // The administrator who created this account.
            $table->foreignId('created_by')
                ->nullable()
                ->after('last_login_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['first_name', 'last_name', 'status', 'last_login_at']);
        });
    }
};
