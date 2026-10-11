<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A provider's claim that they sent SkillServe its commission by GCash,
 * waiting for an administrator to find the reference number in SkillServe's
 * GCash history. Confirming it settles the bookings it covers.
 */
#[Fillable([
    'provider_profile_id', 'amount_due', 'amount_sent', 'reference',
    'proof_path', 'proof_mime_type', 'status',
    'reviewed_by', 'reviewed_at', 'review_notes',
])]
class CommissionRemittance extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::CONFIRMED, self::REJECTED];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    /** The outstanding bookings the payment was for, as they stood when submitted. */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'commission_remittance_bookings')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /** The settlements a confirmation wrote. */
    public function settlements(): HasMany
    {
        return $this->hasMany(CommissionSettlement::class, 'commission_remittance_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected function casts(): array
    {
        return [
            'amount_due' => 'decimal:2',
            'amount_sent' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }
}
