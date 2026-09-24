<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof that SkillServe received its share of a booking. One row per booking,
 * written when an administrator records the remittance; the money itself moves
 * off-platform, exactly as booking payments do (see ADR-007).
 */
#[Fillable([
    'booking_id', 'provider_profile_id', 'amount', 'method',
    'reference', 'notes', 'settled_by', 'settled_at',
])]
class CommissionSettlement extends Model
{
    /** How the remittance reached SkillServe. */
    public const METHODS = ['gcash', 'bank_transfer', 'cash', 'offset', 'other'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }
}
