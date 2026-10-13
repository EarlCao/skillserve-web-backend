<?php

namespace App\Modules\Providers\Models;

use App\Modules\ClientAuthentication\Services\ClientProfileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo of a portfolio item. Like the item's cover, only the storage
 * path is kept; the URL is derived from the configured disk at render time.
 */
class ProviderPortfolioImage extends Model
{
    protected $fillable = [
        'provider_portfolio_item_id',
        'path',
        'position',
    ];

    /** The raw path never leaves the API; callers get {@see url()}. */
    protected $hidden = ['path'];

    protected $casts = [
        'position' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProviderPortfolioItem::class, 'provider_portfolio_item_id');
    }

    public function url(): ?string
    {
        return ClientProfileService::photoUrl($this->path);
    }
}
