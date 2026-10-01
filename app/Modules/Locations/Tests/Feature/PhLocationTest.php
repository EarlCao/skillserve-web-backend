<?php

namespace App\Modules\Locations\Tests\Feature;

use App\Modules\Locations\Tests\SeedsPhLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The address picker's data and the National-ID address matcher, against a
 * slice of the real PSGC (real codes) that includes its awkward cases:
 * NCR cities without a province, a municipality name shared by several
 * provinces, and a province named like one of its cities.
 */
class PhLocationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhLocations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPhLocations();
    }

    public function test_regions_are_listed_in_psgc_order_without_signing_in(): void
    {
        $this->getJson('/api/client/v1/locations/regions')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.code', '030000000')
            ->assertJsonPath('data.0.level', 'region');
    }

    public function test_a_region_lists_its_provinces_and_any_city_without_one(): void
    {
        $this->getJson('/api/client/v1/locations/130000000/children')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Quezon City')
            ->assertJsonPath('data.0.level', 'city');

        $levels = collect($this->getJson('/api/client/v1/locations/040000000/children')->json('data'))->pluck('level')->unique()->values()->all();
        $this->assertSame(['province'], $levels);
    }

    public function test_a_city_lists_its_barangays_by_name(): void
    {
        $this->getJson('/api/client/v1/locations/137404000/children')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bagong Pag-asa')
            ->assertJsonPath('data.1.name', 'San Isidro');
    }

    public function test_an_unknown_or_malformed_code_is_not_found(): void
    {
        $this->getJson('/api/client/v1/locations/999999999/children')->assertNotFound();
        $this->getJson('/api/client/v1/locations/abc/children')->assertNotFound();
    }

    public function test_a_national_id_address_in_ncr_is_matched_without_a_province(): void
    {
        $this->match('123 RIZAL ST, BRGY BAGONG PAG-ASA, QUEZON CITY, METRO MANILA')
            ->assertJsonPath('data.region.code', '130000000')
            ->assertJsonPath('data.province', null)
            ->assertJsonPath('data.city.code', '137404000')
            ->assertJsonPath('data.barangay.code', '137404009')
            ->assertJsonPath('data.street', '123 RIZAL ST');
    }

    public function test_id_spellings_of_a_city_are_understood(): void
    {
        $this->match('PUROK 3, BALIBAGO, CITY OF STA. ROSA, LAGUNA')
            ->assertJsonPath('data.province.code', '043400000')
            ->assertJsonPath('data.city.code', '043428000')
            ->assertJsonPath('data.barangay.code', '043428002')
            ->assertJsonPath('data.street', 'PUROK 3');
    }

    public function test_a_misread_character_is_tolerated(): void
    {
        $this->match('45 MABINI ST, QUEZ0N CITY, NCR')
            ->assertJsonPath('data.city.code', '137404000');
    }

    public function test_the_province_decides_between_municipalities_of_the_same_name(): void
    {
        // "Batangas" is the province here, not Batangas City.
        $this->match('POBLACION, SAN JOSE, BATANGAS')
            ->assertJsonPath('data.city.code', '041022000')
            ->assertJsonPath('data.province.code', '041000000');

        $this->match('POBLACION, SAN JOSE, TARLAC')
            ->assertJsonPath('data.city.code', '036918000');
    }

    public function test_an_ambiguous_address_is_left_for_the_user_rather_than_guessed(): void
    {
        $this->match('POBLACION, SAN JOSE')
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.region', null);
    }

    public function test_an_address_without_commas_still_finds_the_city_and_barangay(): void
    {
        $this->match('BAGONG PAGASA QUEZON CITY')
            ->assertJsonPath('data.city.code', '137404000')
            ->assertJsonPath('data.barangay.code', '137404009')
            ->assertJsonPath('data.street', null);
    }

    public function test_an_address_is_required(): void
    {
        $this->getJson('/api/client/v1/locations/match')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address');
    }

    private function match(string $address): TestResponse
    {
        return $this->getJson('/api/client/v1/locations/match?address='.urlencode($address))->assertOk();
    }
}
