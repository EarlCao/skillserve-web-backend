<?php

namespace App\Modules\Analytics\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_report_requires_authentication(): void
    {
        $this->getJson('/api/analytics/reports?type=users')->assertUnauthorized();
    }

    public function test_report_requires_the_view_permission(): void
    {
        $user = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/analytics/reports?type=users')
            ->assertForbidden();
    }

    public function test_user_report_returns_platform_users_with_counts(): void
    {
        [$token] = $this->actingAnalyst(['view analytics']);
        User::factory()->count(3)->create(['user_type' => 'customer', 'status' => 'active']);
        User::factory()->create(['user_type' => 'provider', 'status' => 'suspended']);

        $this->withToken($token)
            ->getJson('/api/analytics/reports?type=users')
            ->assertOk()
            ->assertJsonPath('message', 'Report generated.')
            ->assertJsonCount(4, 'data');
    }

    public function test_unsupported_report_type_is_rejected(): void
    {
        [$token] = $this->actingAnalyst(['view analytics']);

        $this->withToken($token)
            ->getJson('/api/analytics/reports?type=unknown')
            ->assertUnprocessable();
    }

    public function test_export_requires_the_export_permission(): void
    {
        [$token] = $this->actingAnalyst(['view analytics']);

        $this->withToken($token)
            ->get('/api/analytics/reports/export?type=users')
            ->assertForbidden();
    }

    public function test_export_streams_a_csv(): void
    {
        [$token] = $this->actingAnalyst(['view analytics', 'export analytics']);

        $this->withToken($token)
            ->get('/api/analytics/reports/export?type=users')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_export_neutralizes_formula_injection(): void
    {
        [$token] = $this->actingAnalyst(['view analytics', 'export analytics']);
        User::factory()->create([
            'name' => '=HYPERLINK("http://evil","x")',
            'email' => 'formula@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        $response = $this->withToken($token)
            ->get('/api/analytics/reports/export?type=users')
            ->assertOk();

        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
    }

    public function test_commission_report_lists_booking_commissions(): void
    {
        [$token] = $this->actingAnalyst(['view analytics']);
        $settled = $this->booking(['platform_fee' => 30, 'commission_status' => 'settled']);
        $this->booking(['platform_fee' => 15, 'commission_status' => 'outstanding']);

        $this->withToken($token)
            ->getJson('/api/analytics/reports?type=commissions&status=settled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_number', $settled->booking_number)
            ->assertJsonPath('data.0.platform_fee', '30.00')
            ->assertJsonPath('data.0.commission_status', 'settled');
    }

    public function test_general_report_requires_the_export_permission(): void
    {
        [$token] = $this->actingAnalyst(['view analytics']);

        $this->withToken($token)
            ->get('/api/analytics/reports/general/export')
            ->assertForbidden();
    }

    public function test_general_report_rejects_an_inverted_date_range(): void
    {
        [$token] = $this->actingAnalyst(['view analytics', 'export analytics']);

        $this->withToken($token)
            ->getJson('/api/analytics/reports/general/export?from=2026-09-10&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_general_report_is_a_workbook_with_a_summary_and_a_sheet_per_category(): void
    {
        [$token] = $this->actingAnalyst(['view analytics', 'export analytics']);
        User::factory()->create([
            'name' => '=HYPERLINK("http://evil","x")',
            'email' => 'formula@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        $this->booking(['platform_fee' => 30, 'commission_status' => 'settled']);
        $this->booking(['platform_fee' => 15, 'commission_status' => 'outstanding']);

        $response = $this->withToken($token)
            ->get('/api/analytics/reports/general/export')
            ->assertOk()
            ->assertDownload('general-report-'.now()->format('Y-m-d').'.xlsx');

        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());

        $this->assertSame(
            ['Summary', 'Users', 'Providers', 'Services', 'Bookings', 'Reviews', 'System Activity', 'Commissions'],
            $workbook->getSheetNames(),
        );

        $summary = collect($workbook->getSheetByName('Summary')->toArray())
            ->filter(fn (array $row): bool => $row[0] !== null)
            ->keyBy(0);
        $this->assertSame('All time', $summary['Period'][1]);
        // The formula user plus each booking's customer and provider; the analyst is staff.
        $this->assertEquals(5, $summary['Users'][1]);
        $this->assertStringContainsString('active: 5', $summary['Users'][2]);
        $this->assertEquals(2, $summary['Commissions'][1]);
        $this->assertEquals(30, $summary['Collected (settled)'][1]);
        $this->assertEquals(15, $summary['Outstanding'][1]);

        $users = $workbook->getSheetByName('Users');
        $this->assertSame('Name', $users->getCell('B1')->getValue());
        $names = array_column($users->toArray(), 1);
        $this->assertContains("'=HYPERLINK(\"http://evil\",\"x\")", $names);

        $commissions = $workbook->getSheetByName('Commissions')->toArray();
        $this->assertSame('Commission', $commissions[0][6]);
        $this->assertCount(3, $commissions);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: string}
     */
    private function actingAnalyst(array $permissions): array
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $role = Role::findOrCreate('analyst');
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'email' => 'analyst@skillserve.test',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return [$user->createToken('test')->plainTextToken];
    }

    /** @param  array<string, mixed>  $overrides */
    private function booking(array $overrides): Booking
    {
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'Report Provider']);
        $category = ServiceCategory::create(['name' => 'Report '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Report Service',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        return Booking::create(array_merge([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)),
            'status' => 'completed',
            'payment_status' => 'paid',
            'total_price' => 300,
            'service_price' => 300,
            'commission_rate' => 10,
            'currency' => 'PHP',
        ], $overrides));
    }
}
