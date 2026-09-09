<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('family_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->uuid('replaced_by')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_refresh_tokens');
    }
};
