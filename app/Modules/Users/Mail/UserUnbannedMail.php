<?php

namespace App\Modules\Users\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a ban is lifted from a platform user account — manually by an
 * administrator or automatically because a temporary ban expired.
 */
class UserUnbannedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly ?string $note,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your account has been restored');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.user-unbanned');
    }
}
