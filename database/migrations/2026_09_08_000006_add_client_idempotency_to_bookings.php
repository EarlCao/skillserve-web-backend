<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('client_idempotency_key', 100)->nullable();
            $table->unique(
                ['client_id', 'client_idempotency_key'],
                'bookings_client_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropUnique('bookings_client_idempotency_unique');
            $table->dropColumn('client_idempotency_key');
        });
    }
};
