<?php

namespace App\Modules\Providers\Models;

use App\Modules\ClientAuthentication\Services\ClientProfileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One work sample on a provider's public profile.
 *
 * Only the storage path is kept; the URL is derived from the configured
 * disk at render time, the same way profile photos are handled.
 */
class ProviderPortfolioItem extends Model
{
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
