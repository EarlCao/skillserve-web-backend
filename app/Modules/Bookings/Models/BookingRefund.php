<?php

namespace App\Modules\Bookings\Models;

use App\Models\User;
use App\Shared\Helpers\PrivateImages;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the provider owes back on a cancelled booking that had been (partly)
 * paid, and where returning it stands.
 *
 * due ──provider returns it──▶ sent ──customer confirms──▶ confirmed
 *  │                            └──customer: not received──▶ not_received
 *  └── past due_by: overdue (provider blocked, administrators alerted)
 * not_received / overdue ──administrator──▶ resolved (returned | not_returned)
 */
#[Fillable([
    'booking_id', 'amount', 'status', 'due_by', 'reference', 'proof_path', 'sent_at',
    'confirmed_at', 'customer_note', 'escalated_at', 'resolution', 'resolution_note',
    'resolved_by', 'resolved_at',
])]
class BookingRefund extends Model
{
    public const DISK = 'payment_proofs';

    public const DUE = 'due';

    public const SENT = 'sent';

    public const CONFIRMED = 'confirmed';

    public const NOT_RECEIVED = 'not_received';

    public const RESOLVED = 'resolved';

    /** Statuses still waiting on someone. */
    public const OPEN = [self::DUE, self::SENT, self::NOT_RECEIVED];

    /** The proof screenshot's storage path never leaves the API. */
    protected $hidden = ['proof_path'];

    protected static function booted(): void
    {
        static::deleted(fn (BookingRefund $refund) => PrivateImages::deleteAfterCommit(self::DISK, $refund->proof_path));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_by' => 'datetime',
            'sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Not returned in time: still due and past the deadline. */
    public function isOverdue(): bool
    {
        return $this->status === self::DUE && $this->due_by->isPast();
    }

    /**
     * Refunds that hold a provider back from new work: overdue, reported as
     * not received, or found not returned by an administrator.
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where(fn (Builder $due) => $due->where('status', self::DUE)->where('due_by', '<', now()))
            ->orWhere('status', self::NOT_RECEIVED)
            ->orWhere('resolution', 'not_returned'));
    }
}
