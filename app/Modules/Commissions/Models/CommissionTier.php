<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One configured commission band: "bookings from ₱200 to ₱499.99 are charged
 * 10%". Both bounds are inclusive; a NULL `max_amount` means the band is open
 * ended and covers everything above `min_amount`.
 *
 * Only active, undeleted tiers take part in resolution, so an administrator
 * can retire a band without disturbing the bookings that already used it —
 * those keep their own snapshot of the rate (see booking commissions).
 */
#[Fillable(['name', 'min_amount', 'max_amount', 'percentage', 'is_active', 'created_by', 'updated_by'])]
class CommissionTier extends Model
{
    use SoftDeletes;

    /** Bands eligible to be matched against a booking amount. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Whether this band's inclusive range contains [$amount]. */
    public function covers(float $amount): bool
    {
        return $amount >= (float) $this->min_amount
            && ($this->max_amount === null || $amount <= (float) $this->max_amount);
    }

    /** Whether this band is open ended ("₱1,000 and above"). */
    public function isOpenEnded(): bool
    {
        return $this->max_amount === null;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'percentage' => 'decimal:2',
            'is_active' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }
}
