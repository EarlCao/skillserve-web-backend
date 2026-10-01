<?php

namespace App\Modules\Locations\Tests;

use App\Modules\Locations\Models\PhLocation;

/**
 * A slice of the real PSGC (real codes) with its awkward cases: NCR cities
 * without a province, a municipality name shared by several provinces, and a
 * province named like one of its cities.
 */
trait SeedsPhLocations
{
    private const ROWS = [
        ['130000000', 'National Capital Region (NCR)', 'region', null, '130000000'],
        ['040000000', 'Region IV-A (CALABARZON)', 'region', null, '040000000'],
        ['030000000', 'Region III (Central Luzon)', 'region', null, '030000000'],
        ['043400000', 'Laguna', 'province', '040000000', '040000000'],
        ['045800000', 'Rizal', 'province', '040000000', '040000000'],
        ['041000000', 'Batangas', 'province', '040000000', '040000000'],
        ['036900000', 'Tarlac', 'province', '030000000', '030000000'],
        ['137404000', 'Quezon City', 'city', '130000000', '130000000'],
        ['043428000', 'City of Santa Rosa', 'city', '043400000', '040000000'],
        ['041005000', 'Batangas City', 'city', '041000000', '040000000'],
        ['045805000', 'Cainta', 'municipality', '045800000', '040000000'],
        ['041022000', 'San Jose', 'municipality', '041000000', '040000000'],
        ['036918000', 'San Jose', 'municipality', '036900000', '030000000'],
        ['137404009', 'Bagong Pag-asa', 'barangay', '137404000', '130000000'],
        ['137404098', 'San Isidro', 'barangay', '137404000', '130000000'],
        ['043428002', 'Balibago', 'barangay', '043428000', '040000000'],
        ['045805015', 'San Isidro', 'barangay', '045805000', '040000000'],
    ];

    protected function seedPhLocations(): void
    {
        PhLocation::query()->insert(array_map(fn (array $row): array => array_combine(
            ['code', 'name', 'level', 'parent_code', 'region_code'],
            $row,
        ), self::ROWS));
    }
}
