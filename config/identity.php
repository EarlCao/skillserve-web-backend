<?php

return [
    /*
    |--------------------------------------------------------------------------
    | National ID blind-index pepper
    |--------------------------------------------------------------------------
    |
    | The server-side secret the PhilSys Card Number is HMAC'd with before it
    | is stored or compared. It is what makes "one National ID = one active
    | account" enforceable without keeping the number in the clear.
    |
    | This value must be STABLE for the life of the database. Changing it makes
    | every stored hash unmatchable, so duplicate IDs would stop being
    | detected. It defaults to APP_KEY, which already has to be stable for the
    | encrypted copy of the number to remain readable — so both break together
    | rather than silently diverging. Set IDENTITY_HASH_KEY only when you are
    | prepared to re-hash existing rows.
    |
    */
    'hash_key' => env('IDENTITY_HASH_KEY', env('APP_KEY')),

    /*
    |--------------------------------------------------------------------------
    | Card number shape
    |--------------------------------------------------------------------------
    |
    | The PhilSys Card Number is 16 digits, usually written in groups of four.
    | Input is normalised to digits before validation, so spaces and dashes are
    | accepted from the app.
    |
    */
    'card_number_digits' => 16,

    /*
    |--------------------------------------------------------------------------
    | Document storage
    |--------------------------------------------------------------------------
    |
    | ID images live on a private disk and are never served directly; an
    | administrator opens them through an authorised, audited download.
    |
    */
    'disk' => 'identity',
];
