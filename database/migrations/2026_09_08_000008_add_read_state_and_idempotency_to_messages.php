<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('read_at')->nullable();
            $table->string('client_idempotency_key', 100)->nullable();
            $table->unique(
                ['booking_id', 'sender_id', 'client_idempotency_key'],
                'messages_booking_sender_idempotency_unique',
            );
            $table->index(['receiver_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_receiver_id_read_at_index');
            $table->dropUnique('messages_booking_sender_idempotency_unique');
            $table->dropColumn(['read_at', 'client_idempotency_key']);
        });
    }
};
