<?php

use App\Modules\Payments\Controllers\PayMongoWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payments module API routes
|--------------------------------------------------------------------------
|
| The webhook is called by PayMongo, so it carries no authentication: the
| signature on the raw body is the authorization. It is also exempted from the
| shared `api` throttle — PayMongo controls the delivery rate, including
| retries, and rate-limiting it would drop genuine payment events on the floor.
|
*/

Route::post('/webhooks/paymongo', PayMongoWebhookController::class)
    ->withoutMiddleware(['throttle:api']);
