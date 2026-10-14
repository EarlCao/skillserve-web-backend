<?php

namespace App\Modules\ReportsAndModeration\Models;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Shared\Helpers\PrivateImages;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'sender_id', 'receiver_id', 'booking_id', 'content', 'image_path',
    'status', 'removed_by', 'removed_at', 'deleted_by', 'read_at', 'client_idempotency_key',
])]
class Message extends Model
{
    use SoftDeletes;

    /** The photo's storage path never leaves the API; clients get a URL. */
    protected $hidden = ['image_path'];

    protected static function booted(): void
    {
        // A message removed for good takes its photo with it.
        static::forceDeleted(fn (Message $message) => PrivateImages::deleteAfterCommit('message_images', $message->image_path));
    }

    public function hasImage(): bool
    {
        return filled($this->image_path);
    }

    /** What a list or notification says about the message: its text, or "Photo". */
    public function preview(): string
    {
        $text = trim((string) $this->content);

        return $text !== '' ? $text : ($this->hasImage() ? '📷 Photo' : '');
    }

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
            'read_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
