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

        // GCash goes through PayMongo. Falls back to manual settlement when
        // no secret key is configured, so a deployment without credentials
        // still works exactly as it did before rather than failing at the
        // moment a customer tries to pay.
        PaymentMethod::GCash->value => env('PAYMONGO_SECRET_KEY') ? 'paymongo' : 'manual',
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
        // Never commit these. Set them in the Render environment and in a
        // local .env. `sk_test_` / `pk_test_` keys during development:
        // `sk_live_` moves real money.
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),

        // Issued when the webhook endpoint is registered with PayMongo. This
        // is NOT the secret key — it is the only thing that distinguishes a
        // genuine webhook from a forged one, so a missing value makes the
        // endpoint refuse everything rather than trust it.
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),

        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),

        // Whether to compare the live or the test signature segment of the
        // Paymongo-Signature header. Derived from the secret key so it cannot
        // drift out of step with the credentials in use.
        'live' => str_starts_with((string) env('PAYMONGO_SECRET_KEY'), 'sk_live_'),

        // PayMongo refuses amounts outside this range (in pesos).
        'min_amount' => 1.00,
        'max_amount' => 100000.00,

        // How long a signed webhook is accepted for, in seconds. Beyond this
        // a replayed request is refused even with a valid signature.
        'webhook_tolerance' => (int) env('PAYMONGO_WEBHOOK_TOLERANCE', 300),

        'timeout' => (int) env('PAYMONGO_TIMEOUT', 20),
    ],
];
