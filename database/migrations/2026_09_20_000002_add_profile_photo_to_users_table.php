<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the path of a mobile account's profile photo.
 *
 * Only the storage path is kept; the public URL is derived at render time
 * from the configured disk, so moving photos to S3 later needs no data
 * change. Additive and nullable: existing rows keep a null photo and every
 * current query is unaffected, so this is safe to run ahead of the code
 * that uses it, and rolling it back only discards uploaded photo paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('profile_photo_path')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('profile_photo_path');
        });
    }
};
