<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chat message can carry one photo. The file lives on the private
 * `message_images` disk and is streamed only to the booking's two parties
 * (and to moderators through a report); the column holds its path.
 *
 * Additive and nullable: existing messages are text and keep working; a
 * photo-only message stores an empty `content`. Rollback drops the column —
 * photo messages then show their (empty) text only, and the files stay on
 * disk, unreferenced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};
