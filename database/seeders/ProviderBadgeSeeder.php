<?php

namespace Database\Seeders;

use App\Modules\ProviderRecognition\Models\ProviderBadge;
use Illuminate\Database\Seeder;

/**
 * The default provider badges administrators award from Provider
 * Recognition. Part of the default setup; idempotent (matched on slug, so an
 * administrator's edits to an existing badge are kept).
 */
class ProviderBadgeSeeder extends Seeder
{
    /** @var list<array{name: string, slug: string, description: string, color: string}> */
    public const BADGES = [
        ['name' => 'Top Rated', 'slug' => 'top-rated', 'description' => 'Consistently receives excellent client ratings.', 'color' => 'warning'],
        ['name' => 'Trusted Provider', 'slug' => 'trusted-provider', 'description' => 'Verified provider with a strong platform record.', 'color' => 'success'],
        ['name' => 'Fast Responder', 'slug' => 'fast-responder', 'description' => 'Responds quickly to client inquiries and requests.', 'color' => 'info'],
        ['name' => 'Experienced', 'slug' => 'experienced', 'description' => 'Recognized for experience and completed work.', 'color' => 'primary'],
    ];

    public function run(): void
    {
        foreach (self::BADGES as $badge) {
            ProviderBadge::query()->firstOrCreate(['slug' => $badge['slug']], $badge);
        }
    }
}
