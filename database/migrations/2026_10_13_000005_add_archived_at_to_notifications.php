<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile users can archive notifications to clean up their feed without
 * losing them. Archived ones leave the main feed and the unread count, and
 * are listed on their own.
 *
 * Additive and nullable (nothing is archived to begin with). The index serves
 * the feed's "this user's, not archived, newest first" read. Rollback drops
 * the column and index — archived notifications simply reappear in the feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('read_at');
            $table->index(['notifiable_type', 'notifiable_id', 'archived_at'], 'notifications_notifiable_archived_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_notifiable_archived_index');
            $table->dropColumn('archived_at');
        });
    }
};
