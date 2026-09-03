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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('provider_profiles')->cascadeOnDelete();
            $table->string('booking_number')->unique();
            $table->string('status')->default('pending'); // pending, confirmed, active, completed, cancelled, disputed
            $table->string('payment_status')->default('unpaid'); // unpaid, paid, refunded, partially_refunded
            $table->decimal('total_price', 10, 2);
            $table->decimal('service_price', 10, 2);
            $table->decimal('platform_fee', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('client_notes')->nullable();
            $table->text('provider_notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('scheduled_date')->nullable();
            $table->timestamp('scheduled_end_date')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->string('dispute_status')->nullable(); // pending, investigated, resolved, rejected
            $table->text('dispute_resolution')->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            // Indexes for common queries
            $table->index('status');
            $table->index('payment_status');
            $table->index('booking_number');
            $table->index(['client_id', 'status']);
            $table->index(['provider_id', 'status']);
            $table->index(['service_id', 'status']);
            $table->index('scheduled_date');
            $table->index('created_at');
            $table->index('dispute_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
