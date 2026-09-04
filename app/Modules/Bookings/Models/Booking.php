<?php

namespace App\Modules\Bookings\Models;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Services\Models\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'service_id', 'client_id', 'provider_id', 'booking_number', 'status',
    'payment_status', 'total_price', 'service_price', 'platform_fee', 'currency',
    'payment_method', 'payment_reference', 'client_notes', 'provider_notes',
    'cancellation_reason', 'scheduled_date', 'scheduled_end_date',
    'confirmed_at', 'started_at', 'completed_at', 'cancelled_at',
    'dispute_reason', 'disputed_at', 'dispute_status', 'dispute_resolution',
    'dispute_evidence', 'dispute_notes', 'dispute_closed_at', 'dispute_closed_by',
    'is_reviewed', 'cancelled_by', 'deleted_by',
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

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
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

    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
            'service_price' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'is_reviewed' => 'boolean',
            'scheduled_date' => 'datetime',
            'scheduled_end_date' => 'datetime',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'disputed_at' => 'datetime',
            'dispute_evidence' => 'array',
            'dispute_notes' => 'array',
            'dispute_closed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
