<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only. The admin booking endpoint stored the action name ("resolve",
 * "reject") as the dispute status instead of the outcome, and a rejected
 * dispute left its booking "disputed" for good. Both are corrected here; the
 * code no longer produces either. Idempotent: a second run matches nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')->where('dispute_status', 'resolve')->update(['dispute_status' => 'resolved']);
        DB::table('bookings')->where('dispute_status', 'reject')->update(['dispute_status' => 'rejected']);

        // A rejected dispute returns the job to where it was.
        DB::table('bookings')
            ->where('status', 'disputed')
            ->where('dispute_status', 'rejected')
            ->whereNotNull('completed_at')
            ->update(['status' => 'completed']);
        DB::table('bookings')
            ->where('status', 'disputed')
            ->where('dispute_status', 'rejected')
            ->whereNull('completed_at')
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        // Nothing to undo: the old values were defects, not states to return to.
    }
};
