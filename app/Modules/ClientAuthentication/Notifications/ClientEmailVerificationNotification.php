<?php

namespace App\Modules\ClientAuthentication\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class ClientEmailVerificationNotification extends VerifyEmail
{
    public function verificationUrl(mixed $notifiable): string
    {
        return URL::temporarySignedRoute(
            'client.verification.verify',
            Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            [
                'user' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }
}
