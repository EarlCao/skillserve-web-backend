<?php

namespace App\Modules\ProviderRecognition\Models;

use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'description', 'color', 'is_active'])]
class ProviderBadge extends Model
{
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(
            ProviderProfile::class,
            'provider_badge_assignments',
            'provider_badge_id',
            'provider_profile_id',
        )->withPivot(['assigned_by', 'assigned_at'])->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
