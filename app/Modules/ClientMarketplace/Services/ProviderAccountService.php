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
use Throwable;

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
        // Where customers send payment (ADR-021: SkillServe is not in the
        // payment path, so the provider's own details are what the customer
        // pays into).
        'gcash_number',
        'gcash_name',
        // The provider's GCash QR, as the QR Ph text it encodes; each GCash
        // booking gets a copy with its exact amount.
        'gcash_qr_payload',
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

    /** The provider's work samples, newest first, with their photos. */
    public function portfolio(ProviderProfile $profile): Collection
    {
        return $profile->portfolioItems()->newestFirst()->with('images')->get();
    }

    /**
     * Add a piece of work with one or more photos; the first is its cover.
     * Photos are stored on the same disk as profile photos so both follow
     * one storage configuration.
     *
     * @param  array<int, UploadedFile>  $images
     */
    public function addPortfolioItem(
        ProviderProfile $profile,
        array $images,
        string $title,
        ?string $description,
    ): ProviderPortfolioItem {
        $paths = $this->storeImages($images);

        try {
            return DB::transaction(function () use ($profile, $paths, $title, $description): ProviderPortfolioItem {
                $item = $profile->portfolioItems()->create([
                    'title' => $title,
                    'description' => $description,
                    'image_path' => $paths[0],
                ]);
                $this->attachImages($item, $paths, 0);

                return $item->load('images');
            });
        } catch (Throwable $exception) {
            $this->deleteFiles($paths);

            throw $exception;
        }
    }

    /**
     * Change an item's title or description.
     *
     * @param  array{title?: string, description?: string|null}  $data
     */
    public function updatePortfolioItem(ProviderProfile $profile, int $itemId, array $data): ProviderPortfolioItem
    {
        $item = $this->ownedItem($profile, $itemId);
        $item->update(array_intersect_key($data, array_flip(['title', 'description'])));

        return $item->load('images');
    }

    /**
     * Add photos to an existing item, after the ones it has.
     *
     * @param  array<int, UploadedFile>  $images
     */
    public function addPortfolioImages(ProviderProfile $profile, int $itemId, array $images): ProviderPortfolioItem
    {
        $item = $this->ownedItem($profile, $itemId);
        $count = $item->images()->count();

        if ($count + count($images) > ProviderPortfolioItem::MAX_IMAGES) {
            throw new ApiException(
                'A portfolio item can hold up to '.ProviderPortfolioItem::MAX_IMAGES.' photos.',
                422,
                errors: ['images' => ['This item has '.$count.' photos; you can add '.max(0, ProviderPortfolioItem::MAX_IMAGES - $count).' more.']],
            );
        }

        $paths = $this->storeImages($images);

        try {
            DB::transaction(function () use ($item, $paths): void {
                // Re-checked under a lock: two uploads at once must not pass
                // the limit between them.
                ProviderPortfolioItem::query()->whereKey($item->id)->lockForUpdate()->first();
                if ($item->images()->count() + count($paths) > ProviderPortfolioItem::MAX_IMAGES) {
                    throw new ApiException(
                        'A portfolio item can hold up to '.ProviderPortfolioItem::MAX_IMAGES.' photos.',
                        422,
                        errors: ['images' => ['Too many photos for this item.']],
                    );
                }

                $next = (int) $item->images()->max('position') + 1;
                $this->attachImages($item, $paths, $next);
                // The item is shown as changed (it sorts by creation, so
                // its place in the gallery stays).
                $item->touch();
            });
        } catch (Throwable $exception) {
            $this->deleteFiles($paths);

            throw $exception;
        }

        return $item->load('images');
    }

    /**
     * Remove one photo. An item always keeps at least one; removing the
     * cover makes the next photo the cover.
     */
    public function removePortfolioImage(ProviderProfile $profile, int $itemId, int $imageId): ProviderPortfolioItem
    {
        $item = $this->ownedItem($profile, $itemId);
        $image = $item->images()->whereKey($imageId)->first()
            ?? throw new ApiException('Photo not found.', 404);

        if ($item->images()->count() <= 1) {
            throw new ApiException(
                'A portfolio item needs at least one photo. Delete the item instead.',
                422,
                errors: ['image' => ['This is the item\'s only photo.']],
            );
        }

        DB::transaction(function () use ($item, $image): void {
            $image->delete();
            $item->update(['image_path' => $item->images()->value('path')]);
        });

        $this->deleteFiles([$image->path]);

        return $item->load('images');
    }

    /**
     * Remove one of the provider's own work samples, and its files.
     *
     * The item is looked up through the provider's own relation, so an id
     * belonging to somebody else simply is not found.
     */
    public function removePortfolioItem(ProviderProfile $profile, int $itemId): void
    {
        $item = $this->ownedItem($profile, $itemId);
        $paths = $item->images()->pluck('path')->push($item->image_path)->unique()->all();

        DB::transaction(static function () use ($item): void {
            $item->delete();
        });

        $this->deleteFiles($paths);
    }

    private function ownedItem(ProviderProfile $profile, int $itemId): ProviderPortfolioItem
    {
        return $profile->portfolioItems()->whereKey($itemId)->first()
            ?? throw new ApiException('Portfolio item not found.', 404);
    }

    /**
     * @param  array<int, UploadedFile>  $images
     * @return array<int, string>
     */
    private function storeImages(array $images): array
    {
        $paths = [];

        try {
            foreach (array_values($images) as $image) {
                $paths[] = $image->store('portfolio', ClientProfileService::disk());
            }
        } catch (Throwable $exception) {
            $this->deleteFiles($paths);

            throw $exception;
        }

        return $paths;
    }

    /** @param  array<int, string>  $paths */
    private function attachImages(ProviderPortfolioItem $item, array $paths, int $firstPosition): void
    {
        foreach ($paths as $offset => $path) {
            $item->images()->create(['path' => $path, 'position' => $firstPosition + $offset]);
        }
    }

    /** @param  array<int, string|null>  $paths */
    private function deleteFiles(array $paths): void
    {
        $paths = array_values(array_filter($paths));
        if ($paths !== []) {
            Storage::disk(ClientProfileService::disk())->delete($paths);
        }
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
