<?php

namespace App\Shared\Helpers;

/**
 * Phone numbers on SkillServe are Philippine mobile numbers, stored in one
 * shape: 11 digits starting with 09 (09123456789). Whatever is typed —
 * +63 912 345 6789, 639123456789, 9123456789 — is normalised to that before
 * validation, so the number customers see always looks like the one they
 * dial or enter in GCash.
 *
 * The one place this rule lives: the profile phone, the booking contact phone,
 * the provider's GCash number and the admin's user edit all use it.
 */
final class PhilippineMobileNumber
{
    public const PATTERN = '/^09\d{9}$/';

    public const MESSAGE = 'Enter an 11-digit Philippine mobile number starting with 09, e.g. 09123456789.';

    /**
     * The number as 09XXXXXXXXX, or null when nothing was entered. Input that
     * is not a number is returned as typed, so validation refuses it rather
     * than silently clearing the field.
     */
    public static function normalise(mixed $value): ?string
    {
        $typed = trim((string) $value);
        if ($typed === '') {
            return null;
        }

        // Spaces, dashes, dots, brackets and a leading + are formatting.
        $digits = preg_replace('/[\s\-\.\(\)]/', '', $typed) ?? $typed;
        $digits = ltrim($digits, '+');
        if (! ctype_digit($digits)) {
            return $typed;
        }

        return match (true) {
            str_starts_with($digits, '639') && strlen($digits) === 12 => '0'.substr($digits, 2),
            str_starts_with($digits, '9') && strlen($digits) === 10 => '0'.$digits,
            default => $digits,
        };
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return ['string', 'regex:'.self::PATTERN];
    }
}
