<?php

namespace App\Modules\Bookings\Models;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionSettlement;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'service_id', 'client_id', 'provider_id', 'booking_number', 'status',
    'payment_status', 'total_price', 'service_price', 'platform_fee',
    'commission_rate', 'commission_tier_id', 'commission_status',
    'commission_settled_at', 'currency',
    'payment_method', 'payment_reference', 'paid_at', 'payment_recorded_by',
    'refunded_amount', 'refunded_at', 'refund_reason', 'client_notes', 'provider_notes',
    'service_address', 'contact_phone',
    'cancellation_reason', 'cancellation_fee', 'scheduled_date', 'scheduled_end_date',
    'confirmed_at', 'started_at', 'completed_at', 'cancelled_at', 'rescheduled_at',
    'dispute_reason', 'disputed_at', 'dispute_status', 'dispute_resolution',
    'dispute_evidence', 'dispute_notes', 'dispute_closed_at', 'dispute_closed_by',
    'is_reviewed', 'client_idempotency_key', 'cancelled_by', 'deleted_by',
])]
class Booking extends Model
{
    use SoftDeletes;

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class, 'booking_id');
    }

    /**
     * Messages exchanged on this booking. A booking is the conversation: the
     * client and the provider only ever message each other about a job.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'booking_id');
    }

    /**
     * The newest message still visible, used for conversation previews. Ties
     * on created_at break by id so the preview is deterministic.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'booking_id')->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->where('status', 'active'),
        );
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function paymentRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_recorded_by');
    }

    /** Proof that SkillServe's share of this booking was remitted, if it was. */
    public function commissionSettlement(): HasOne
    {
        return $this->hasOne(CommissionSettlement::class, 'booking_id');
    }

    /** The commission band this booking was charged under, if any. */
    public function commissionTier(): BelongsTo
    {
        return $this->belongsTo(CommissionTier::class, 'commission_tier_id');
    }

    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function disputeClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispute_closed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true);
    }

    /** A booking can move to a new time until the provider starts the job. */
    public function isReschedulable(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true);
    }

    public function cancellationPaymentPolicy(): string
    {
        return match ($this->payment_status) {
            'unpaid' => 'unpaid_no_refund_due',
            'refunded' => 'already_refunded',
            default => 'payment_unchanged_refund_not_processed',
        };
    }

    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
            'service_price' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_settled_at' => 'datetime',
            'refunded_amount' => 'decimal:2',
            'cancellation_fee' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'is_reviewed' => 'boolean',
            'scheduled_date' => 'datetime',
            'scheduled_end_date' => 'datetime',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'disputed_at' => 'datetime',
            'dispute_evidence' => 'array',
            'dispute_notes' => 'array',
            'dispute_closed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
