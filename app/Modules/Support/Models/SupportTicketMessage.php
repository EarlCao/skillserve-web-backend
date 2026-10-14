<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['support_ticket_id', 'author_id', 'body'])]
class SupportTicketMessage extends Model
{
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    protected static function booted(): void
    {
        // The database would drop the reply's photo rows on its own (cascade),
        // leaving their files behind; delete them through the model instead.
        static::deleting(fn (SupportTicketMessage $message) => $message->attachments()->get()->each->delete());
    }

    /** Photos sent with this reply. */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class)->orderBy('id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
