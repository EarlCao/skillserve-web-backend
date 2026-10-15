<?php

namespace App\Modules\Users\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an administrator deletes an account, with the reason — the
 * person can no longer sign in to read a notification about it.
 */
class UserDeletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly ?string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your account has been deleted');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.user-deleted');
    }
}
