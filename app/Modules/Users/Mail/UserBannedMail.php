<?php

namespace App\Modules\Users\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Sent when a platform user account is banned — either for a fixed number of
 * days (with the auto-lift date) or permanently.
 */
class UserBannedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Carbon|null  $bannedUntil  null = permanent ban
     */
    public function __construct(
        public readonly User $user,
        public readonly string $reason,
        public readonly ?Carbon $bannedUntil = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->bannedUntil
                ? 'Your account has been temporarily banned'
                : 'Your account has been banned',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.user-banned');
    }
}
