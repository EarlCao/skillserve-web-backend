<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work samples a provider uploads to their own profile.
 *
 * A table rather than the legacy `provider_profiles.portfolio` JSON column,
 * because items are addressed individually (deleted by id, listed newest
 * first, counted) and a JSON column cannot be indexed or constrained. The
 * column is left untouched — nothing reads it — so this migration only adds
 * storage and changes no existing read path.
 *
 * Cascades on delete because an item has no meaning without its provider
 * profile, which is itself removed only when the account is hard-deleted.
 *
 * Additive and safe to run ahead of the code that uses it; rolling it back
 * discards uploaded work samples only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_portfolio_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_profile_id')
                ->constrained('provider_profiles')
                ->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_path');
            $table->timestamps();

            // The gallery is always read newest-first for a single provider.
            $table->index(['provider_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_portfolio_items');
    }
};
