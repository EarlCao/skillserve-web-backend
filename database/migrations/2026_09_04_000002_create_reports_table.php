<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports submitted against users, services, reviews or messages.
     *
     * The reported item is stored as a polymorphic relation (reportable) so a
     * single table serves all four report types. Moderation state lives on the
     * report itself: investigation notes (append-only JSON), the resolution /
     * rejection stamps, and the moderation action that was taken.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->morphs('reportable');
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 100);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending'); // pending, investigating, resolved, rejected
            $table->json('investigation_notes')->nullable();
            $table->foreignId('investigated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('investigated_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->string('moderation_action', 30)->nullable(); // warning, suspend, ban, hide, remove
            $table->foreignId('action_taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('action_taken_at')->nullable();
            $table->text('action_note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('status');
            $table->index('reason');
            $table->index('reportable_type');
            $table->index('reporter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
