<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Modules\ClientAuthentication\Services\ClientProfileService;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\Providers\Models\ProviderPortfolioItem;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A provider maintaining their own account from the mobile app: their
 * professional profile, their portfolio, and the recognition badges they
 * have earned.
 *
 * Every method takes the provider profile resolved from the authenticated
 * user, so no identifier is ever accepted from the request and one provider
 * can never reach another's data.
 */
class ProviderAccountService
{
    /**
     * Fields a provider may change about themselves.
     *
     * Verification state, featured status, ratings and booking counters are
     * deliberately absent: those are earned or set by administrators, and
     * letting a provider write them would let them mark themselves verified.
     */
    private const EDITABLE = [
        'business_name',
        'bio',
        'specialization',
        'experience_years',
        'hourly_rate',
        'location',
        'website',
        'skills',
        'certifications',
        'languages',
    ];

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateProfile(ProviderProfile $profile, array $validated): ProviderProfile
    {
        $attributes = array_intersect_key($validated, array_flip(self::EDITABLE));

        if ($attributes !== []) {
            $profile->fill($attributes)->save();
        }

        return $profile->fresh();
    }

    /** The weekly hours the provider publishes, ordered as the week reads. */
    public function availability(ProviderProfile $profile): Collection
    {
        return $profile->availabilities()->get();
    }

    /**
     * Replace the provider's published schedule and/or their "taking new
     * bookings" flag.
     *
     * `availability` is the whole week: the old windows are removed and the
     * submitted ones inserted in one transaction, so the schedule is never
     * half-written. Omitting the key leaves the schedule untouched.
     *
     * @param  array<string, mixed>  $validated
     */
    public function updateAvailability(ProviderProfile $profile, array $validated): ProviderProfile
    {
        return DB::transaction(function () use ($profile, $validated): ProviderProfile {
            if (array_key_exists('is_accepting_bookings', $validated)) {
                $profile->is_accepting_bookings = (bool) $validated['is_accepting_bookings'];
                $profile->save();
            }

            if (array_key_exists('availability', $validated)) {
                $profile->availabilities()->delete();

                $windows = collect($validated['availability'])
                    ->map(fn (array $window): array => [
                        'day_of_week' => (int) $window['day_of_week'],
                        'start_time' => $window['start_time'],
                        'end_time' => $window['end_time'],
                    ])
                    ->all();

                if ($windows !== []) {
                    $profile->availabilities()->createMany($windows);
                }
            }

            return $profile->fresh();
        });
    }

    /** The provider's work samples, newest first. */
    public function portfolio(ProviderProfile $profile): Collection
    {
        return $profile->portfolioItems()->newestFirst()->get();
    }

    /**
     * Add a work sample. The image is stored on the same disk as profile
     * photos so both follow one storage configuration.
     */
    public function addPortfolioItem(
        ProviderProfile $profile,
        UploadedFile $image,
        string $title,
        ?string $description,
    ): ProviderPortfolioItem {
        $path = $image->store('portfolio', ClientProfileService::disk());

        return $profile->portfolioItems()->create([
            'title' => $title,
            'description' => $description,
            'image_path' => $path,
        ]);
    }

    /**
     * Remove one of the provider's own work samples, and its file.
     *
     * The item is looked up through the provider's own relation, so an id
     * belonging to somebody else simply is not found.
     */
    public function removePortfolioItem(ProviderProfile $profile, int $itemId): void
    {
        $item = $profile->portfolioItems()->whereKey($itemId)->first()
            ?? throw new ApiException('Portfolio item not found.', 404);

        $path = $item->image_path;

        DB::transaction(static function () use ($item): void {
            $item->delete();
        });

        Storage::disk(ClientProfileService::disk())->delete($path);
    }

    /**
     * Every active badge, with the ones this provider has earned marked as
     * such — the Badges screen shows progress toward the rest, so it needs
     * the whole catalogue, not only the assignments.
     *
     * @return array{earned: Collection, available: Collection}
     */
    public function badges(ProviderProfile $profile): array
    {
        $earned = $profile->badges()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $available = ProviderBadge::query()
            ->where('is_active', true)
            ->whereNotIn('id', $earned->pluck('id'))
            ->orderBy('name')
            ->get();

        return ['earned' => $earned, 'available' => $available];
    }
}
