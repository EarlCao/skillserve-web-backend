<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_archives', function (Blueprint $table): void {
            $table->id();
            $table->string('resource_type', 50);
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at');
            $table->json('previous_state')->nullable();
            $table->timestamps();
            $table->unique(['resource_type', 'resource_id']);
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_archives');
    }
};
