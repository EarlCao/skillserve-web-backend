<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several photos per portfolio item: a piece of work is shown as a small
 * album (before, during, after) rather than a single picture.
 *
 * - Additive: a new table; `provider_portfolio_items.image_path` stays and
 *   keeps holding the item's cover (its first photo), so older app versions
 *   that read `image` keep working.
 * - Backfill: every existing item's single photo becomes its photo #1
 *   (position 0). Nothing is moved on disk.
 * - Cascades on delete because a photo row has no meaning without its item;
 *   the files themselves are removed by the service that deletes them.
 *
 * Rollback drops the table: items keep their cover photo and lose only the
 * extra photos added since (their files stay on disk, unreferenced).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_portfolio_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_portfolio_item_id')
                ->constrained('provider_portfolio_items')
                ->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // An item's photos are always read in order.
            $table->index(['provider_portfolio_item_id', 'position']);
        });

        DB::table('provider_portfolio_items')
            ->select(['id', 'image_path', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                DB::table('provider_portfolio_images')->insert($items->map(fn ($item): array => [
                    'provider_portfolio_item_id' => $item->id,
                    'path' => $item->image_path,
                    'position' => 0,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_portfolio_images');
    }
};
