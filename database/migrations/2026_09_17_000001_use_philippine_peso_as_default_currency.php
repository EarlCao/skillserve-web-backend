<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform operates only in the Philippines. Switch the currency default
 * to PHP and relabel records created with the old USD default (amounts are
 * already peso values, so only the currency code changes).
 */
return new class extends Migration
{
    private const TABLES = ['services', 'bookings'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('currency', 3)->default('PHP')->change();
            });

            DB::table($tableName)->where('currency', 'USD')->update(['currency' => 'PHP']);
        }
    }

    public function down(): void
    {
        // Relabelled rows are not reverted: the original USD values cannot be
        // told apart from records genuinely created in PHP.
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('currency', 3)->default('USD')->change();
            });
        }
    }
};
