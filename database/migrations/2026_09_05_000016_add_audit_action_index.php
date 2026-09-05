<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name', 'activity_log'), function (Blueprint $table): void {
            $table->index(['description', 'created_at'], 'activity_log_description_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name', 'activity_log'), function (Blueprint $table): void {
            $table->dropIndex('activity_log_description_created_at_index');
        });
    }
};
