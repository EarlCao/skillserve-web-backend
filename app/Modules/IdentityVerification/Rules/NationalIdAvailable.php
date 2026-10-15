<?php

namespace App\Modules\IdentityVerification\Rules;

use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\IdentityVerification\Services\NationalIdHasher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Sign-up's early check that the scanned National ID is not already held by
 * another account of the same type, so a second provider (or customer)
 * account is refused before it is created. The submission itself re-checks
 * under a lock, and the database index is the final guard.
 */
class NationalIdAvailable implements ValidationRule
{
    /**
     * @param  string  $accountType  'provider' or 'customer'
     */
    public function __construct(private readonly string $accountType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $hasher = app(NationalIdHasher::class);

        if (! is_string($value) || ! $hasher->isWellFormed($value)) {
            return;
        }

        if (IdentityVerification::query()->holdingId($hasher->hash($value), $this->accountType)->exists()) {
            $fail(IdentityVerification::idInUseMessage($this->accountType));
        }
    }
}
