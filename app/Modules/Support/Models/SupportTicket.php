<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[Fillable([
    'ticket_number', 'requester_id', 'subject', 'description', 'category',
    'priority', 'status', 'assigned_to', 'assigned_by', 'assigned_at',
    'resolved_by', 'resolved_at', 'resolution_note',
])]
class SupportTicket extends Model
{
    use SoftDeletes;

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    /** Every photo on the ticket: its own and its replies'. */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class)->orderBy('id');
    }

    /**
     * The loaded photos sent with the ticket itself ([messageId] null) or with
     * one reply.
     *
     * @return Collection<int, SupportTicketAttachment>
     */
    public function attachmentsOf(?int $messageId): Collection
    {
        return $this->attachments
            ->filter(fn (SupportTicketAttachment $attachment): bool => $attachment->support_ticket_message_id === ($messageId === null ? null : (int) $messageId))
            ->values();
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'resolved_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
