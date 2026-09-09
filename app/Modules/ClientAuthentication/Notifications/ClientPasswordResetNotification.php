<?php

namespace App\Modules\ClientAuthentication\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ClientPasswordResetNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = rtrim((string) config('client-auth.password_reset_url'), '?&');
        $url .= '?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your SkillServe password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
