<?php

use App\Modules\Bookings\Enums\PaymentMethod;

return [
    /*
    |--------------------------------------------------------------------------
    | Gateway per payment method
    |--------------------------------------------------------------------------
    |
    | Both methods are settled directly between the customer and the provider:
    | SkillServe records the payment, it never collects it (ADR-007, ADR-021).
    |
    | The PayMongo gateway below is built and tested, but no booking is routed
    | to it — see the note on the mapping itself.
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
    | No keys are committed. Bookings never use PayMongo (both methods map to
    | the manual gateway above). What does use it is a provider paying their
    | OWN commission by a dynamic QR Ph code (CommissionQrPaymentService):
    | that money is SkillServe's, so collecting it keeps ADR-021 intact.
    | Without a key the app simply offers the GCash-reference way instead.
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

        // Issued when the webhook endpoint is registered with PayMongo
        // (https://<backend>/api/webhooks/paymongo, events payment.paid,
        // payment.failed and qrph.expired). This
        // is NOT the secret key — it is the only thing that distinguishes a
        // genuine webhook from a forged one, so a missing value makes the
        // endpoint refuse everything rather than trust it.
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),

        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),

        // Whether to compare the live or the test signature segment of the
        // Paymongo-Signature header. Derived from the secret key so it cannot
        // drift out of step with the credentials in use.
        'live' => str_starts_with((string) env('PAYMONGO_SECRET_KEY'), 'sk_live_'),

        // Whether an `sk_live_` key may be used at all.
        //
        // Off by default: a live key collects real commission payments, so it
        // is switched on deliberately once the QR Ph flow has been rehearsed
        // with test keys — never by a key merely appearing in the environment.
        // PayMongoClient refuses every request while a live key is configured
        // and this is false, and says so in the log.
        //
        // Turning it on is a deliberate decision that belongs with whoever
        // accepts the consequence — it is not a deployment detail.
        'allow_live' => (bool) env('PAYMONGO_ALLOW_LIVE', false),

        // PayMongo refuses amounts outside this range (in pesos).
        'min_amount' => 1.00,
        'max_amount' => 100000.00,

        // How long a signed webhook is accepted for, in seconds. Beyond this
        // a replayed request is refused even with a valid signature.
        'webhook_tolerance' => (int) env('PAYMONGO_WEBHOOK_TOLERANCE', 300),

        'timeout' => (int) env('PAYMONGO_TIMEOUT', 20),
    ],
];
