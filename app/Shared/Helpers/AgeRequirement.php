<?php

namespace App\Shared\Helpers;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * SkillServe is for adults: a customer or provider must be at least
 * {@see self::MINIMUM_AGE} years old, and a provider's years of experience
 * are counted from age {@see self::WORK_START_AGE} at the earliest — so an
 * 18-year-old can claim 2 years, a 19-year-old 3, a 20-year-old 4, and so on.
 *
 * The one place these rules live; every request that takes a birthday or
 * years of experience builds its rules from here.
 */
final class AgeRequirement
{
    public const MINIMUM_AGE = 18;

    public const WORK_START_AGE = 16;

    /** The cap when the birthday is unknown (accounts made before birthdays were collected). */
    public const MAX_EXPERIENCE_YEARS = 80;

    /** The latest birthday that is old enough today. */
    public static function latestBirthday(): string
    {
        return Carbon::today()->subYears(self::MINIMUM_AGE)->toDateString();
    }

    /** @return list<string> */
    public static function birthdayRules(): array
    {
        return ['required', 'date', 'after:1900-01-01', 'before_or_equal:'.self::latestBirthday()];
    }

    /**
     * The most years of experience someone born on $birthday can claim.
     * Unknown or unreadable birthdays fall back to the general cap; the
     * birthday's own rules reject an unreadable one.
     */
    public static function maxExperienceYears(mixed $birthday): int
    {
        if ($birthday === null || $birthday === '') {
            return self::MAX_EXPERIENCE_YEARS;
        }

        try {
            $age = Carbon::parse($birthday)->age;
        } catch (Throwable) {
            return self::MAX_EXPERIENCE_YEARS;
        }

        return max(0, min(self::MAX_EXPERIENCE_YEARS, $age - self::WORK_START_AGE));
    }

    /** @return list<string> */
    public static function experienceRules(mixed $birthday): array
    {
        return ['integer', 'min:0', 'max:'.self::maxExperienceYears($birthday)];
    }

    /** @return array<string, string> */
    public static function messages(string $birthdayField = 'birthday'): array
    {
        return [
            $birthdayField.'.required' => 'Enter your date of birth.',
            $birthdayField.'.before_or_equal' => 'You must be at least '.self::MINIMUM_AGE.' years old to use SkillServe.',
            'experience_years.max' => 'At your age, years of experience can be at most :max.',
        ];
    }
}
