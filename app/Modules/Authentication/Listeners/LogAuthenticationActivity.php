<?php

namespace App\Modules\Authentication\Listeners;

use App\Modules\Authentication\Events\AdministratorLoggedIn;
use App\Modules\Authentication\Events\AdministratorLoggedOut;
use App\Modules\Authentication\Events\AdministratorLoginFailed;
use App\Modules\Authentication\Events\PasswordChanged;

/**
 * Persists authentication events into the Spatie activity log.
 *
 * Future extensibility (email notifications, device tracking, ...) can be
 * added here or as sibling listeners registered in AppServiceProvider.
 */
class LogAuthenticationActivity
{
    /**
     * Handle the authentication events (registered explicitly in
     * AppServiceProvider because the module lives outside app/Listeners).
     */
    public function handle(
        AdministratorLoggedIn|AdministratorLoggedOut|AdministratorLoginFailed|PasswordChanged $event,
    ): void {
        if ($event instanceof AdministratorLoginFailed) {
            activity('authentication')
                ->withProperties(array_filter([
                    'email' => $event->email,
                    'ip' => $event->ip,
                    'user_agent' => $event->userAgent,
                ]))
                ->log('administrator_login_failed');

            return;
        }

        $properties = array_filter([
            'ip' => $event->ip,
            'user_agent' => $event->userAgent,
        ]);

        activity('authentication')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->withProperties($properties)
            ->log(match (true) {
                $event instanceof AdministratorLoggedIn => 'administrator_logged_in',
                $event instanceof AdministratorLoggedOut => 'administrator_logged_out',
                default => 'password_changed',
            });
    }
}
