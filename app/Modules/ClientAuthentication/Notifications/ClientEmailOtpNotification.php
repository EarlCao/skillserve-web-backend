<?php

namespace App\Modules\ClientAuthentication\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientEmailOtpNotification extends Notification
{
    use Queueable;

    /** Confirms the address at sign-up. */
    public const PURPOSE_VERIFY = 'verify';

    /** Proves the address before a forgotten password is replaced. */
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public function __construct(
        private readonly string $code,
        private readonly int $expiresMinutes,
        public readonly string $purpose = self::PURPOSE_VERIFY,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isReset = $this->purpose === self::PURPOSE_PASSWORD_RESET;

        return (new MailMessage)
            ->subject($isReset ? 'Your SkillServe password reset code' : 'Your SkillServe verification code')
            ->greeting('Hi '.$notifiable->first_name.',')
            ->line($isReset
                ? 'Use this one-time code to reset your SkillServe password:'
                : 'Use this one-time code to verify your SkillServe account:')
            ->line('**'.$this->code.'**')
            ->line("This code expires in {$this->expiresMinutes} minutes and can only be used once.")
            ->line($isReset
                ? 'If you did not ask to reset your password, you can safely ignore this email; your password stays the same.'
                : 'If you did not create a SkillServe account, you can safely ignore this email.');
    }
}
