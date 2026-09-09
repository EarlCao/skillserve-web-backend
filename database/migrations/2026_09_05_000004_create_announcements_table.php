<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->text('message');
            $table->string('target', 20); // all, customers, providers, selected
            $table->json('recipient_ids')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('status', 20)->default('sent'); // scheduled, sent, failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('created_by');
        });

        // SQLite does not support ALTER TABLE ... ADD CONSTRAINT. Keep the
        // named PostgreSQL constraints while allowing SQLite test migrations.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("alter table announcements add constraint announcements_target_check check (target in ('all', 'customers', 'providers', 'selected'))");
            DB::statement("alter table announcements add constraint announcements_status_check check (status in ('pending', 'scheduled', 'sent', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
