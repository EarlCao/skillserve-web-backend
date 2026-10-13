<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos sent as proof with a report or a support ticket. Each row points at
 * a file in private storage; moderators and support staff open it through an
 * authorised download.
 *
 * Additive: two new tables, nothing existing changes. A hard-deleted report
 * or ticket takes its attachment rows with it (both are soft-deleted in
 * normal use); an uploader's account deletion only clears `uploaded_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();

            $table->index('report_id');
        });

        Schema::create('support_ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            // Null for photos sent with the ticket itself; set for a reply's.
            $table->foreignId('support_ticket_message_id')->nullable()->constrained('support_ticket_messages')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();

            $table->index('support_ticket_id');
            $table->index('support_ticket_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
        Schema::dropIfExists('report_attachments');
    }
};
