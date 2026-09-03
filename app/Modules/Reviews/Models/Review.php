<?php

namespace App\Modules\Reviews\Models;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Services\Models\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'booking_id', 'reviewer_id', 'provider_id', 'service_id',
    'rating', 'comment', 'status', 'is_reported', 'report_reason',
    'hidden_by', 'hidden_at', 'removed_by', 'removed_at', 'deleted_by',
])]
class Review extends Model
{
    use SoftDeletes;

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isHidden(): bool
    {
        return $this->status === 'hidden';
    }

    public function isRemoved(): bool
    {
        return $this->status === 'removed';
    }

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_reported' => 'boolean',
            'hidden_at' => 'datetime',
            'removed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
