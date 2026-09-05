<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_badges', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->string('color', 30)->default('primary');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('provider_badge_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained('provider_profiles')->cascadeOnDelete();
            $table->foreignId('provider_badge_id')->constrained('provider_badges')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['provider_profile_id', 'provider_badge_id']);
            $table->index('assigned_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_badge_assignments');
        Schema::dropIfExists('provider_badges');
    }
};
