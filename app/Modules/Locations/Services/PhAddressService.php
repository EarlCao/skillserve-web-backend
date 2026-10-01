<?php

namespace App\Modules\Locations\Services;

use App\Modules\Locations\Models\PhLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One structured Philippine address, stored as PSGC codes beside a formatted
 * text copy (see the 2026_10_01_000002 migration).
 *
 * The client sends only the most specific place — a barangay for a door
 * address, a city or municipality for a service area — and the server derives
 * the levels above it, so a barangay can never be saved under the wrong city.
 *
 * Two kinds:
 * - **street**: barangay required, plus optional street and ZIP (a home or a
 *   booking's service address);
 * - **area**: city/municipality required, barangay optional (where a service
 *   is offered).
 *
 * Registered as a singleton so place names looked up while presenting a list
 * are reused for the rest of the request.
 */
class PhAddressService
{
    public const STREET = 'street';

    public const AREA = 'area';

    private const LOCALITIES = [PhLocation::CITY, PhLocation::MUNICIPALITY];

    /** @var array<string, PhLocation> */
    private array $places = [];

    /**
     * Validation rules for an address object sent at [$key].
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $key, string $kind, bool $required = false): array
    {
        $rules = [
            $key => [$required ? 'required' : 'sometimes', 'nullable', 'array'],
            "{$key}.barangay_code" => [
                $kind === self::STREET ? "required_with:{$key}" : 'nullable',
                'nullable', 'string', 'size:9',
                Rule::exists('ph_locations', 'code')->where('level', PhLocation::BARANGAY),
            ],
        ];

        if ($kind === self::AREA) {
            $rules["{$key}.city_code"] = [
                "required_with:{$key}", 'string', 'size:9',
                Rule::exists('ph_locations', 'code')->whereIn('level', self::LOCALITIES),
            ];
        } else {
            $rules["{$key}.street"] = ['nullable', 'string', 'max:255'];
            $rules["{$key}.postal_code"] = ['nullable', 'string', 'regex:/^\d{4}$/'];
        }

        return $rules;
    }

    /**
     * The column values for a validated address object, under [$prefix], and
     * its formatted text. Null clears the address.
     *
     * @param  array<string, mixed>|null  $details
     * @return array{columns: array<string, string|null>, formatted: string|null}
     *
     * @throws ValidationException when an area's barangay is not in its city
     */
    public function resolve(?array $details, string $prefix, string $kind, string $key): array
    {
        if ($details === null) {
            return ['columns' => $this->columns($prefix, $kind, []), 'formatted' => null];
        }

        $barangay = isset($details['barangay_code']) ? $this->place($details['barangay_code']) : null;
        $locality = $kind === self::AREA
            ? $this->place($details['city_code'])
            : $this->place($barangay->parent_code);

        if ($barangay && $barangay->parent_code !== $locality->code) {
            throw ValidationException::withMessages([
                "{$key}.barangay_code" => ['The barangay is not in the selected city or municipality.'],
            ]);
        }

        $province = $locality->parent_code !== $locality->region_code ? $this->place($locality->parent_code) : null;
        $street = $kind === self::STREET ? (trim((string) ($details['street'] ?? '')) ?: null) : null;
        $postal = $kind === self::STREET ? ($details['postal_code'] ?? null) : null;

        $values = [
            'region_code' => $locality->region_code,
            'province_code' => $province?->code,
            'city_code' => $locality->code,
            'barangay_code' => $barangay?->code,
            'street' => $street,
            'postal_code' => $postal,
        ];

        return [
            'columns' => $this->columns($prefix, $kind, $values),
            'formatted' => $this->format($street, $barangay, $locality, $province, $postal),
        ];
    }

    /**
     * A stored address as the app reads it, or null when [$model] has none
     * under [$prefix].
     *
     * @return array<string, mixed>|null
     */
    public function present(Model $model, string $prefix, string $kind): ?array
    {
        $cityCode = $model->getAttribute("{$prefix}_city_code");

        if ($cityCode === null) {
            return null;
        }

        $codes = array_filter([
            $model->getAttribute("{$prefix}_region_code"),
            $model->getAttribute("{$prefix}_province_code"),
            $cityCode,
            $model->getAttribute("{$prefix}_barangay_code"),
        ]);
        $this->remember($codes);

        $item = fn (?string $code): ?array => $code && isset($this->places[$code])
            ? ['code' => $code, 'name' => $this->places[$code]->name]
            : null;

        $address = [
            'region' => $item($model->getAttribute("{$prefix}_region_code")),
            'province' => $item($model->getAttribute("{$prefix}_province_code")),
            'city' => $item($cityCode),
            'barangay' => $item($model->getAttribute("{$prefix}_barangay_code")),
        ];

        if ($kind === self::STREET) {
            $address['street'] = $model->getAttribute("{$prefix}_street");
            $address['postal_code'] = $model->getAttribute("{$prefix}_postal_code");
        }

        return $address;
    }

    /**
     * "123 Rizal St, Bagong Pag-asa, Quezon City, Metro Manila 1105". NCR has
     * no province, so its cities end with "Metro Manila" instead.
     */
    private function format(?string $street, ?PhLocation $barangay, PhLocation $locality, ?PhLocation $province, ?string $postal): string
    {
        $area = $province?->name ?? ($locality->region_code === '130000000'
            ? 'Metro Manila'
            : $this->place($locality->region_code)->name);

        $text = implode(', ', array_filter([$street, $barangay?->name, $locality->name, $area]));

        return $postal ? "{$text} {$postal}" : $text;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string|null>
     */
    private function columns(string $prefix, string $kind, array $values): array
    {
        $names = ['region_code', 'province_code', 'city_code', 'barangay_code'];

        if ($kind === self::STREET) {
            array_push($names, 'street', 'postal_code');
        }

        $columns = [];
        foreach ($names as $name) {
            $columns["{$prefix}_{$name}"] = $values[$name] ?? null;
        }

        return $columns;
    }

    private function place(string $code): PhLocation
    {
        $this->remember([$code]);

        return $this->places[$code] ?? throw ValidationException::withMessages([
            'address' => ['That place is not in the Philippine location list.'],
        ]);
    }

    /** @param  array<int, string>  $codes */
    private function remember(array $codes): void
    {
        $missing = array_values(array_diff($codes, array_keys($this->places)));

        if ($missing !== []) {
            PhLocation::query()->whereIn('code', $missing)->get()
                ->each(fn (PhLocation $place) => $this->places[$place->code] = $place);
        }
    }
}
