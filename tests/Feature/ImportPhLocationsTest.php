<?php

namespace Tests\Feature;

use App\Modules\Locations\Models\PhLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class ImportPhLocationsTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'psgc').'.json.gz';
        file_put_contents($this->path, gzencode(json_encode([
            'source' => 'test slice',
            'rows' => [
                ['130000000', 'National Capital Region (NCR)', 'region', null, '130000000'],
                ['137404000', 'Quezon City', 'city', '130000000', '130000000'],
            ],
        ])));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_it_loads_once_and_replaces_only_when_asked(): void
    {
        $this->artisan('locations:import', ['--path' => $this->path])->assertSuccessful();
        $this->assertSame(2, PhLocation::query()->count());

        PhLocation::query()->whereKey('137404000')->update(['name' => 'Edited']);

        $this->artisan('locations:import', ['--path' => $this->path])
            ->expectsOutputToContain('already loaded')
            ->assertSuccessful();
        $this->assertSame('Edited', PhLocation::query()->find('137404000')->name);

        $this->artisan('locations:import', ['--path' => $this->path, '--fresh' => true])->assertSuccessful();
        $this->assertSame('Quezon City', PhLocation::query()->find('137404000')->name);
    }

    public function test_a_missing_snapshot_fails_loudly(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->artisan('locations:import', ['--path' => '/nonexistent/psgc.json.gz']);
    }

    /**
     * The committed snapshot itself: readable, complete, and every place's
     * parent present, so the pickers can never reach a dead end.
     *
     * Runs in its own process: decoding ~44k rows would otherwise leave the
     * rest of the suite with too little memory.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_shipped_snapshot_is_a_complete_tree(): void
    {
        $rows = json_decode(gzdecode(file_get_contents(database_path('data/ph_locations.json.gz'))), true)['rows'];

        // One pass and one code => level map: the full list is ~44k rows, so
        // collection copies of it would weigh on the whole suite's memory.
        $levels = [];
        foreach ($rows as [$code, , $level]) {
            $levels[$code] = $level;
        }

        $this->assertGreaterThan(40000, count($rows));
        $this->assertCount(count($rows), $levels, 'duplicate codes');
        $this->assertSame(17, count(array_keys($levels, 'region', true)));

        foreach ($rows as [$code, , $level, $parent]) {
            $this->assertTrue($parent === null || isset($levels[$parent]), "orphaned place {$code}");

            if ($level === 'barangay') {
                $this->assertContains($levels[$parent], ['city', 'municipality'], "barangay {$code} outside a city or municipality");
            }
        }

        unset($rows, $levels);
    }
}
