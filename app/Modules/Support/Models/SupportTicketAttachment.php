<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo sent with a support ticket ([support_ticket_message_id] null) or
 * with one of its replies, on the private `support_attachments` disk.
 */
#[Fillable(['support_ticket_id', 'support_ticket_message_id', 'uploaded_by', 'path', 'mime_type', 'size_bytes'])]
#[Hidden(['path'])]
class SupportTicketAttachment extends Model
{
    public const DISK = 'support_attachments';

    /** How many photos the ticket, or one reply, may carry. */
    public const MAX_PER_POST = 5;

    protected function casts(): array
    {
        return [
            'support_ticket_id' => 'integer',
            'support_ticket_message_id' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
