<?php

namespace App\Modules\Locations\Services;

use App\Modules\Locations\Models\PhLocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The address pickers' data: the next level down from any place, and the
 * best guess for a free-text address such as the one printed on a National
 * ID ("123 RIZAL ST, BRGY SAN ISIDRO, QUEZON CITY, METRO MANILA").
 */
class PhLocationService
{
    /** Words the IDs and the PSGC spell differently, mapped to one spelling. */
    private const SPELLINGS = [
        'STA' => 'SANTA', 'STO' => 'SANTO', 'SN' => 'SAN', 'POB' => 'POBLACION',
        'GEN' => 'GENERAL', 'PRES' => 'PRESIDENT',
    ];

    /** Words that only say what kind of place follows. */
    private const NOISE = ['BRGY', 'BGY', 'BARANGAY', 'BRY', 'MUNICIPALITY', 'PROVINCE'];

    /** What people write for Metro Manila, which has no province. */
    private const NCR_ALIASES = ['METRO MANILA', 'NCR', 'NATIONAL CAPITAL REGION', 'MM'];

    private const NCR_CODE = '130000000';

    /** @return Collection<int, PhLocation> */
    public function regions(): Collection
    {
        return PhLocation::query()->where('level', PhLocation::REGION)->orderBy('code')->get();
    }

    /**
     * The places one level below [$location]. A region's children are its
     * provinces plus any city without a province (all of NCR, for example),
     * so the app shows them in one list and moves on from whichever is picked.
     *
     * @return Collection<int, PhLocation>
     */
    public function children(PhLocation $location): Collection
    {
        return PhLocation::query()->where('parent_code', $location->code)->orderBy('name')->get();
    }

    /**
     * The place and every place above it, region first.
     *
     * @return array<int, PhLocation>
     */
    public function lineage(PhLocation $location): array
    {
        $chain = [$location];

        while ($location->parent_code !== null && ($location = PhLocation::query()->find($location->parent_code))) {
            array_unshift($chain, $location);
        }

        return $chain;
    }

    /**
     * Best guess for [$address]: the region, province, city or municipality
     * and barangay it names, and the street part before them. Anything that
     * cannot be decided is null rather than guessed, and the user confirms
     * the result in the picker either way.
     *
     * @return array{region: PhLocation|null, province: PhLocation|null, locality: PhLocation|null, barangay: PhLocation|null, street: string|null}
     */
    public function match(string $address): array
    {
        $parts = $this->parts($address);
        $result = ['region' => null, 'province' => null, 'locality' => null, 'barangay' => null, 'street' => null];

        if ($parts === []) {
            return $result;
        }

        $provinces = PhLocation::query()->where('level', PhLocation::PROVINCE)->get();
        $localities = PhLocation::query()->whereIn('level', [PhLocation::CITY, PhLocation::MUNICIPALITY])->get();

        $mentionsNcr = collect($parts)->contains(fn (string $part): bool => in_array($part, self::NCR_ALIASES, true));
        $provinceMatch = $this->bestMatch($provinces, $parts);
        $province = $provinceMatch['location'];

        // Several municipalities share a name ("San Jose" is in a dozen
        // provinces), so a province or NCR named alongside decides between
        // them; without one, an ambiguous name is left for the user.
        $candidates = $this->allMatches($localities, $parts);

        // "Batangas" names the province; it is not also "Batangas City".
        if ($province) {
            $elsewhere = $candidates->reject(fn (array $c): bool => $c['index'] === $provinceMatch['index']);
            $candidates = $elsewhere->isNotEmpty() ? $elsewhere : $candidates;
        }
        $within = $candidates->filter(fn (array $c): bool => ($province && $c['location']->parent_code === $province->code)
            || ($mentionsNcr && $c['location']->region_code === self::NCR_CODE));
        $pool = $within->isNotEmpty() ? $within : $candidates;
        $best = $pool->groupBy(fn (array $c): int => $c['distance'])->sortKeys()->first();
        $locality = $best && $best->pluck('location')->unique('code')->count() === 1 ? $best->first() : null;

        if ($locality) {
            $result['locality'] = $locality['location'];
            $result['province'] = $locality['location']->parent_code !== $locality['location']->region_code
                ? PhLocation::query()->find($locality['location']->parent_code)
                : null;
            $result['region'] = PhLocation::query()->find($locality['location']->region_code);

            // The barangay is named before the city on an address.
            $before = array_slice($parts, 0, $locality['index']);
            $barangay = $this->bestMatch($this->children($locality['location']), $before);

            if ($barangay['location']) {
                $result['barangay'] = $barangay['location'];
                $before = array_slice($parts, 0, $barangay['index']);
            }

            // Only a comma-separated address says where the street ends.
            $result['street'] = $this->hasCommas($address) ? $this->street($address, count($before)) : null;
        } elseif ($province) {
            $result['province'] = $province;
            $result['region'] = PhLocation::query()->find($province->region_code);
        } elseif ($mentionsNcr) {
            $result['region'] = PhLocation::query()->find(self::NCR_CODE);
        }

        return $result;
    }

    /**
     * The address as comparable pieces: one per comma-separated part, plus
     * every run of up to four words so an address without commas still
     * matches. The order of the original parts is kept.
     *
     * @return array<int, string>
     */
    private function parts(string $address): array
    {
        $pieces = array_values(array_filter(array_map(
            fn (string $piece): string => $this->key($piece),
            preg_split('/[,\n;]+/', $address) ?: [],
        )));

        if (count($pieces) > 1) {
            return $pieces;
        }

        $words = explode(' ', $pieces[0] ?? '');
        $runs = [];

        foreach ($words as $start => $word) {
            for ($length = 1; $length <= 4 && $start + $length <= count($words); $length++) {
                $runs[] = implode(' ', array_slice($words, $start, $length));
            }
        }

        return array_values(array_filter($runs));
    }

    /**
     * The closest of [$locations] to any of [$parts], preferring an exact
     * match and allowing a misread character or two in longer names.
     *
     * @param  iterable<PhLocation>  $locations
     * @param  array<int, string>  $parts
     * @return array{location: PhLocation|null, index: int, distance: int}
     */
    private function bestMatch(iterable $locations, array $parts): array
    {
        $best = $this->allMatches($locations, $parts)->sortBy([['distance', 'asc'], ['index', 'desc']])->first();

        return $best ?? ['location' => null, 'index' => 0, 'distance' => PHP_INT_MAX];
    }

    /**
     * @param  iterable<PhLocation>  $locations
     * @param  array<int, string>  $parts
     * @return \Illuminate\Support\Collection<int, array{location: PhLocation, index: int, distance: int}>
     */
    private function allMatches(iterable $locations, array $parts): \Illuminate\Support\Collection
    {
        $matches = collect();

        foreach ($locations as $location) {
            foreach ($this->keys($location) as $key => $penalty) {
                foreach ($parts as $index => $part) {
                    $distance = $part === $key ? 0 : $this->distance($part, $key);

                    if ($distance !== null) {
                        $matches->push(['location' => $location, 'index' => $index, 'distance' => $distance + $penalty]);
                    }
                }
            }
        }

        return $matches;
    }

    /** Edit distance when it is small enough to be a misread, otherwise null. */
    private function distance(string $part, string $key): ?int
    {
        // One misread character in a name of five letters or more, two in a
        // long one; short names must match exactly.
        $allowed = match (true) {
            strlen($key) >= 12 => 2,
            strlen($key) >= 5 => 1,
            default => 0,
        };

        if ($allowed === 0 || abs(strlen($part) - strlen($key)) > $allowed) {
            return null;
        }

        $distance = levenshtein($part, $key);

        return $distance <= $allowed ? $distance : null;
    }

    /**
     * The comparable forms of a place's name, each with a penalty added to
     * its distance: the name itself, without any bracketed part ("San Isidro
     * (Pob.)" also answers to "San Isidro"), and for a city both "X City" and
     * "City of X" exactly, and plain "X" only as a weaker match, because a
     * province or municipality is often called "X" too.
     *
     * @return array<string, int>
     */
    private function keys(PhLocation $location): array
    {
        $plain = preg_replace('/\s*\([^)]*\)/', '', $location->name) ?? $location->name;
        $keys = [$this->key($location->name) => 0, $this->key($plain) => 0];

        if ($location->level === PhLocation::CITY) {
            $base = preg_replace('/^City of\s+|\s+City$/i', '', $plain) ?? $plain;
            $keys += [$this->key($base.' City') => 0, $this->key('City of '.$base) => 0, $this->key($base) => 1];
        }

        unset($keys['']);

        return $keys;
    }

    /** Upper case, no accents or punctuation, one spelling per word, no filler. */
    private function key(string $text): string
    {
        $text = Str::upper(Str::ascii($text));
        $words = preg_split('/[^A-Z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(fn (string $word): string => self::SPELLINGS[$this->unmisread($word)] ?? $this->unmisread($word), $words);

        return implode(' ', array_values(array_diff($words, self::NOISE)));
    }

    private function hasCommas(string $address): bool
    {
        return count($this->parts($address)) > 1 && preg_match('/[,\n;]/', $address) === 1;
    }

    /**
     * A camera reading a printed card confuses O/0, I/1, S/5 and B/8. Inside a
     * word that also has letters the digit is the misread; a number on its own
     * ("Barangay 1") is kept.
     */
    private function unmisread(string $word): string
    {
        if (! preg_match('/[A-Z]/', $word) || ! preg_match('/[0-9]/', $word)) {
            return $word;
        }

        return strtr($word, ['0' => 'O', '1' => 'I', '5' => 'S', '8' => 'B']);
    }

    /** The original text of the first [$count] comma-separated parts. */
    private function street(string $address, int $count): ?string
    {
        if ($count === 0) {
            return null;
        }

        $original = array_values(array_filter(array_map('trim', preg_split('/[,\n;]+/', $address) ?: [])));
        $street = implode(', ', array_slice($original, 0, $count));

        return $street === '' ? null : $street;
    }
}
