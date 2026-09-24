<?php

namespace App\Modules\IdentityVerification\Services;

use App\Shared\Exceptions\ApiException;

/**
 * Turns a PhilSys Card Number into the two forms the platform stores: a blind
 * index for comparison, and the last four digits for display.
 *
 * The blind index is HMAC-SHA256 under a server-side pepper
 * (`config('identity.hash_key')`). A plain hash would be trivially reversible
 * — the whole number space is only 10^16 and an attacker with the database
 * could enumerate it — so the pepper, which lives outside the database, is
 * what makes a leaked hash useless on its own.
 *
 * Normalisation strips everything but digits, so "1234-5678-9012-3456",
 * "1234 5678 9012 3456" and the bare digits all resolve to the same index.
 * Without that, the same person could hold several accounts simply by typing
 * their number differently.
 */
class NationalIdHasher
{
    /** Digits only, so formatting never changes the identity of a number. */
    public function normalise(string $cardNumber): string
    {
        return preg_replace('/\D/', '', $cardNumber) ?? '';
    }

    public function isWellFormed(string $cardNumber): bool
    {
        return strlen($this->normalise($cardNumber)) === (int) config('identity.card_number_digits', 16);
    }

    /** The comparison value stored in `id_number_hash`. */
    public function hash(string $cardNumber): string
    {
        $normalised = $this->normalise($cardNumber);

        if (! $this->isWellFormed($cardNumber)) {
            throw new ApiException('That National ID number is not valid.', 422, errors: [
                'id_number' => ['Enter the 16 digits printed on your PhilSys card.'],
            ]);
        }

        $key = (string) config('identity.hash_key');

        if ($key === '') {
            // Hashing under an empty key would produce a value an attacker
            // could reproduce offline, silently defeating the whole scheme.
            throw new ApiException('Identity verification is not configured.', 500);
        }

        return hash_hmac('sha256', $normalised, $key);
    }

    /** The only part of the number ever shown back to anyone. */
    public function last4(string $cardNumber): string
    {
        return substr($this->normalise($cardNumber), -4);
    }
}
