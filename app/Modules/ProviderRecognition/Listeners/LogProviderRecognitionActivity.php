<?php

namespace App\Modules\ProviderRecognition\Listeners;

use App\Modules\ProviderRecognition\Events\ProviderRecognitionChanged;

class LogProviderRecognitionActivity
{
    public function handle(ProviderRecognitionChanged $event): void
    {
        activity('provider_recognition')
            ->causedBy($event->actor)
            ->performedOn($event->subject)
            ->withProperties($event->properties)
            ->log($event->action);
    }
}
