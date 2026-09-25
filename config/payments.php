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
        // BOTH methods are settled directly between the customer and the
        // provider. SkillServe is never in the payment path and never holds
        // customer money: the customer sends GCash to the provider's own
        // number (or pays cash on the job), and the provider then owes
        // SkillServe its commission.
        //
        // This is deliberate and must not be flipped to 'paymongo' for a
        // booking. Collecting the booking total into SkillServe's account
        // would make SkillServe owe every provider their share — a payout
        // obligation the platform has no mechanism for, and one that carries
        // licensing exposure for holding other people's money.
        // See ADR-021.
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
        // Never commit these. Set them in the Render environment and in a
        // local .env. `sk_test_` / `pk_test_` keys during development:
        // `sk_live_` moves real money.
        'secret_key' => env('PAYMONGO_SECRET_KEY'),

        // No public key: intents are created server-side, so the publishable
        // key is only needed for in-browser card tokenisation, which
        // SkillServe does not do.

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
