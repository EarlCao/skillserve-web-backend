<?php

namespace Database\Seeders;

use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Database\Seeder;

class ProviderRecognitionSeeder extends Seeder
{
    public function run(): void
    {
        $badges = collect([
            ['name' => 'Top Rated', 'slug' => 'top-rated', 'description' => 'Consistently receives excellent client ratings.', 'color' => 'warning'],
            ['name' => 'Trusted Provider', 'slug' => 'trusted-provider', 'description' => 'Verified provider with a strong platform record.', 'color' => 'success'],
            ['name' => 'Fast Responder', 'slug' => 'fast-responder', 'description' => 'Responds quickly to client inquiries and requests.', 'color' => 'info'],
            ['name' => 'Experienced', 'slug' => 'experienced', 'description' => 'Recognized for experience and completed work.', 'color' => 'primary'],
        ])->mapWithKeys(fn (array $data): array => [
            $data['slug'] => ProviderBadge::query()->firstOrCreate(['slug' => $data['slug']], $data),
        ]);

        $providers = ProviderProfile::query()->orderBy('id')->limit(4)->get();

        foreach ($providers as $index => $provider) {
            $provider->update(['is_featured' => $index < 2]);

            if ($badge = $badges->get($index === 0 ? 'top-rated' : 'trusted-provider')) {
                $provider->badges()->syncWithoutDetaching([$badge->id]);
            }
        }
    }
}
