<?php

namespace App\Modules\Authentication\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The reset link for an administrator, pointing at the admin web's reset page.
 */
class AdminPasswordResetNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = config('app.frontend_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your SkillServe admin password')
            ->line('We received a request to reset the password for your SkillServe admin account.')
            ->action('Reset password', $url)
            ->line('This link expires in 60 minutes and can be used once.')
            ->line('If you did not ask for this, ignore this email — your password stays the same.');
    }
}
