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
            // Product surface the account belongs to. Regular platform users
            // default to "customer"; providers arrive with the Provider module.
            $table->string('user_type')->default('customer')->after('email_verified_at');

            // Personal profile fields surfaced in the user profile.
            $table->string('phone', 30)->nullable()->after('email');
            $table->text('address')->nullable()->after('phone');
            $table->date('birthday')->nullable()->after('address');

            // Temporary suspension (User Management module).
            $table->timestamp('suspended_at')->nullable()->after('status');
            $table->foreignId('suspended_by')->nullable()->after('suspended_at')->constrained('users')->nullOnDelete();
            $table->string('suspension_reason', 500)->nullable()->after('suspended_by');

            // Reactivation — restores a suspended account.
            $table->timestamp('activated_at')->nullable()->after('suspension_reason');
            $table->foreignId('activated_by')->nullable()->after('activated_at')->constrained('users')->nullOnDelete();

            // Permanent ban.
            $table->timestamp('banned_at')->nullable()->after('activated_by');
            $table->foreignId('banned_by')->nullable()->after('banned_at')->constrained('users')->nullOnDelete();
            $table->string('ban_reason', 500)->nullable()->after('banned_by');

            // Soft delete — accounts are never hard-removed.
            $table->softDeletes()->after('ban_reason');
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
            $table->dropConstrainedForeignId('banned_by');
            $table->dropConstrainedForeignId('activated_by');
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn([
                'user_type',
                'phone',
                'address',
                'birthday',
                'suspended_at',
                'suspension_reason',
                'activated_at',
                'banned_at',
                'ban_reason',
            ]);
        });
    }
};
