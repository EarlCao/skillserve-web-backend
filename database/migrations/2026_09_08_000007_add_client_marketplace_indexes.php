<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->index(['client_id', 'created_at'], 'bookings_client_created_index');
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->index(['reviewer_id', 'created_at'], 'reviews_reviewer_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex('reviews_reviewer_created_index');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_client_created_index');
        });
    }
};
