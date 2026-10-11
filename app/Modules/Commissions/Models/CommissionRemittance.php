<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A provider paying SkillServe its commission, one of two ways:
 *
 * - `gcash_reference`: they sent SkillServe's GCash and typed the reference;
 *   an administrator finds it in SkillServe's GCash history and confirms it.
 * - `qrph`: PayMongo made a QR Ph code for the exact amount; PayMongo's
 *   webhook confirms the payment.
 *
 * Confirming either settles the bookings it covers.
 */
#[Fillable([
    'provider_profile_id', 'method', 'amount_due', 'amount_sent', 'reference',
    'external_id', 'external_payment_id', 'proof_path', 'proof_mime_type',
    'qr_image', 'expires_at', 'last_event_id', 'failure_reason', 'status',
    'reviewed_by', 'reviewed_at', 'review_notes',
])]
#[Hidden(['qr_image'])]
class CommissionRemittance extends Model
{
    /** The provider typed the GCash reference; an administrator confirms it. */
    public const METHOD_GCASH_REFERENCE = 'gcash_reference';

    /** A PayMongo QR Ph code; PayMongo's webhook confirms it. */
    public const METHOD_QRPH = 'qrph';

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    /** A QR code nobody paid before it stopped working. */
    public const EXPIRED = 'expired';

    /** A QR code PayMongo could not create, or a payment it reported failed. */
    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::CONFIRMED, self::REJECTED, self::EXPIRED, self::FAILED];

    public function isQr(): bool
    {
        return $this->method === self::METHOD_QRPH;
    }

    /** A QR code that can still be scanned and paid. */
    public function isPayableQr(): bool
    {
        return $this->isQr()
            && $this->status === self::PENDING
            && $this->qr_image !== null
            && $this->expires_at?->isFuture() === true;
    }

    /**
     * Open claims a person has to look at: every GCash-reference claim, and a
     * QR one only when its payment needs a decision (an amount that did not
     * match). A QR code waiting to be scanned is PayMongo's to confirm.
     */
    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)
            ->where(fn (Builder $inner) => $inner
                ->where('method', self::METHOD_GCASH_REFERENCE)
                ->orWhereNotNull('failure_reason'));
    }

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
            'expires_at' => 'datetime',
        ];
    }
}
