<?php

namespace App\Modules\Providers\Models;

use App\Modules\ClientAuthentication\Services\ClientProfileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of work on a provider's public profile: a title, a description
 * and up to {@see MAX_IMAGES} photos. `image_path` is the cover — always the
 * first photo — kept so older app versions that show one image still work.
 *
 * Only storage paths are kept; URLs are derived from the configured disk at
 * render time, the same way profile photos are handled.
 */
class ProviderPortfolioItem extends Model
{
    /** Photos one item may hold. */
    public const MAX_IMAGES = 10;

    protected $fillable = [
        'provider_profile_id',
        'title',
        'description',
        'image_path',
    ];

    /** The raw path never leaves the API; callers get {@see imageUrl()}. */
    protected $hidden = ['image_path'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    /** The item's photos, cover first. */
    public function images(): HasMany
    {
        return $this->hasMany(ProviderPortfolioImage::class)->orderBy('position')->orderBy('id');
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Absolute URL of the stored image. */
    public function imageUrl(): ?string
    {
        return ClientProfileService::photoUrl($this->image_path);
    }
}
