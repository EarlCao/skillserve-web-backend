<?php

use App\Modules\Bookings\Enums\PaymentMethod;

return [
    /*
    |--------------------------------------------------------------------------
    | Gateway per payment method
    |--------------------------------------------------------------------------
    |
    | Both methods are settled by hand today: the customer pays the provider
    | off-platform and somebody records it (see ADR-007).
    |
    | Pointing `gcash` at "paymongo" is what switches GCash to live online
    | payment. Do NOT change it until the PayMongo integration is actually
    | implemented — the gateway throws on every money-moving call, by design.
    |
    */
    'gateways' => [
        PaymentMethod::OnHand->value => 'manual',
        PaymentMethod::GCash->value => 'manual',
    ],

    /*
    |--------------------------------------------------------------------------
    | PayMongo credentials
    |--------------------------------------------------------------------------
    |
    | Placeholders only. These are intentionally empty: no keys are committed,
    | and none have been issued for this project. The integration is not built.
    |
    */
    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
    ],
];
