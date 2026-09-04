<?php

namespace App\Modules\ReportsAndModeration\Models;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'sender_id', 'receiver_id', 'booking_id', 'content',
    'status', 'removed_by', 'removed_at', 'deleted_by',
])]
class Message extends Model
{
    use SoftDeletes;

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isRemoved(): bool
    {
        return $this->status === 'removed';
    }

    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
