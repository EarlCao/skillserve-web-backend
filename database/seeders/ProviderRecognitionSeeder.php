<?php

namespace Database\Seeders;

use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Database\Seeder;

class ProviderRecognitionSeeder extends Seeder
{
    /** Demo only: features two providers and awards them badges. */
    public function run(): void
    {
        $this->call(ProviderBadgeSeeder::class);

        $badges = ProviderBadge::query()->get()->keyBy('slug');

        $providers = ProviderProfile::query()->orderBy('id')->limit(4)->get();

        foreach ($providers as $index => $provider) {
            $provider->update(['is_featured' => $index < 2]);

            if ($badge = $badges->get($index === 0 ? 'top-rated' : 'trusted-provider')) {
                $provider->badges()->syncWithoutDetaching([$badge->id]);
            }
        }
    }
}
