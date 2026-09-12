<?php

namespace App\Modules\ClientAuthentication\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientEmailOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly int $expiresMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your SkillServe verification code')
            ->greeting('Hi '.$notifiable->first_name.',')
            ->line('Use this one-time code to verify your SkillServe account:')
            ->line('**'.$this->code.'**')
            ->line("This code expires in {$this->expiresMinutes} minutes and can only be used once.")
            ->line('If you did not create a SkillServe account, you can safely ignore this email.');
    }
}
