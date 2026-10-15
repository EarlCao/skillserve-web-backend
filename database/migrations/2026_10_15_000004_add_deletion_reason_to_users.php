<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an administrator deleted an account, so the person can be told — in
 * the sign-out message their open app receives and by email — and Data
 * Management can show it.
 *
 * Additive: one nullable text column, no backfill (accounts deleted earlier
 * simply have no recorded reason). Deploy before or with the code. Rollback
 * drops the column and the reasons recorded since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('deletion_reason')->nullable()->after('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('deletion_reason');
        });
    }
};
