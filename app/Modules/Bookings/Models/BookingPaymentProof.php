<?php

namespace App\Modules\Bookings\Models;

use App\Models\User;
use App\Shared\Helpers\PrivateImages;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's proof of a GCash payment to the provider — a downpayment or
 * the rest — with a screenshot, for the provider to confirm or reject.
 */
#[Fillable([
    'booking_id', 'submitted_by', 'amount', 'reference', 'image_path',
    'status', 'reviewed_by', 'reviewed_at', 'review_note',
])]
class BookingPaymentProof extends Model
{
    public const DISK = 'payment_proofs';

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    /** The screenshot's storage path never leaves the API. */
    protected $hidden = ['image_path'];

    protected static function booted(): void
    {
        static::deleted(fn (BookingPaymentProof $proof) => PrivateImages::deleteAfterCommit(self::DISK, $proof->image_path));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
