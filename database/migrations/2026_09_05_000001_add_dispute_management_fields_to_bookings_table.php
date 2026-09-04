<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->json('dispute_evidence')->nullable()->after('dispute_resolution');
            $table->json('dispute_notes')->nullable()->after('dispute_evidence');
            $table->timestamp('dispute_closed_at')->nullable()->after('dispute_notes');
            $table->foreignId('dispute_closed_by')->nullable()->after('dispute_closed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dispute_closed_by');
            $table->dropColumn(['dispute_evidence', 'dispute_notes', 'dispute_closed_at']);
        });
    }
};
