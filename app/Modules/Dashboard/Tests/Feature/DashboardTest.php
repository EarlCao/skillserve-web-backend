<?php

namespace App\Modules\Dashboard\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_requires_the_dashboard_permission(): void
    {
        $user = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/dashboard')
            ->assertForbidden();
    }

    public function test_dashboard_returns_platform_summaries_and_analytics(): void
    {
        [$token] = $this->actingAdministrator();
        User::factory()->count(2)->create(['user_type' => 'customer', 'status' => 'active']);
        User::factory()->create(['user_type' => 'provider', 'status' => 'suspended']);

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.user_summary.total_clients', 2)
            ->assertJsonPath('data.user_summary.total_providers', 1)
            ->assertJsonStructure([
                'data' => [
                    'user_summary',
                    'service_summary',
                    'booking_summary',
                    'verification_summary',
                    'reports_summary',
                    'recent_activities',
                    'analytics' => ['monthly_activity', 'booking_statuses', 'user_statuses'],
                ],
            ]);
    }

    public function test_commission_summary_shows_collected_commission_as_pesos_and_a_percentage(): void
    {
        [$token] = $this->actingAdministrator(['view dashboard', 'view commissions']);
        CommissionTier::create(['name' => 'Top', 'min_amount' => 1000, 'max_amount' => null, 'percentage' => 20, 'is_active' => true]);
        CommissionTier::create(['name' => 'Base', 'min_amount' => 0, 'max_amount' => 999.99, 'percentage' => 10, 'is_active' => true]);
        $this->booking(['total_price' => 500, 'platform_fee' => 50, 'commission_status' => 'settled']);
        $this->booking(['total_price' => 1500, 'platform_fee' => 300, 'commission_status' => 'settled']);
        $this->booking(['total_price' => 400, 'platform_fee' => 40, 'commission_status' => 'outstanding']);
        $this->booking(['total_price' => 200, 'platform_fee' => 20, 'commission_status' => 'waived']);

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.commission_summary.collected', '350.00')
            ->assertJsonPath('data.commission_summary.collected_booking_value', '2000.00')
            ->assertJsonPath('data.commission_summary.collected_rate', '17.50')
            ->assertJsonPath('data.commission_summary.outstanding', '40.00')
            ->assertJsonPath('data.commission_summary.waived', '20.00')
            ->assertJsonPath('data.commission_summary.rate_source', 'tiers')
            ->assertJsonPath('data.commission_summary.tiers.0.name', 'Base')
            ->assertJsonPath('data.commission_summary.tiers.0.percentage', '10.00')
            ->assertJsonPath('data.commission_summary.tiers.1.max_amount', null);
    }

    public function test_commission_summary_reports_the_flat_rate_when_no_tier_is_active(): void
    {
        [$token] = $this->actingAdministrator(['view dashboard', 'view commissions']);

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.commission_summary.collected', '0.00')
            ->assertJsonPath('data.commission_summary.collected_rate', '0.00')
            ->assertJsonPath('data.commission_summary.rate_source', 'fallback')
            ->assertJsonPath('data.commission_summary.tiers', []);
    }

    public function test_commission_summary_is_hidden_without_a_commission_permission(): void
    {
        [$token] = $this->actingAdministrator();

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonMissingPath('data.commission_summary');
    }

    /** @param  array<string, mixed>  $overrides */
    public function test_attention_counts_new_tickets_and_pending_reports(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        foreach (['open', 'open', 'in_progress', 'resolved'] as $status) {
            SupportTicket::create([
                'ticket_number' => 'SUP-'.Str::upper(Str::random(12)), 'requester_id' => $customer->id,
                'subject' => 'Help', 'description' => 'Help me.', 'category' => 'general',
                'priority' => 'normal', 'status' => $status,
            ]);
        }
        foreach (['pending', 'pending', 'pending', 'investigating', 'resolved'] as $status) {
            // One report per reporter and target, as the table enforces.
            $reporter = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
            Report::create([
                'reportable_type' => $customer->getMorphClass(), 'reportable_id' => $customer->id,
                'reporter_id' => $reporter->id, 'reason' => 'fraud', 'description' => 'Look.', 'status' => $status,
            ]);
        }
        [$token] = $this->actingAdministrator(['view support', 'view reports']);

        $this->withToken($token)->getJson('/api/dashboard/attention')
            ->assertOk()
            ->assertJsonPath('data', ['open_support_tickets' => 2, 'pending_reports' => 3]);
    }

    public function test_attention_hides_a_count_the_viewer_may_not_see(): void
    {
        // Every permission exists in production (seeded); here only the role's.
        Permission::findOrCreate('view reports');
        // Support staff without the dashboard permission still get their badge.
        [$token] = $this->actingAdministrator(['view support']);

        $this->withToken($token)->getJson('/api/dashboard/attention')
            ->assertOk()
            ->assertJsonPath('data', ['open_support_tickets' => 0, 'pending_reports' => null]);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/dashboard/attention')->assertUnauthorized();
    }

    private function booking(array $overrides): Booking
    {
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'Dashboard Provider']);
        $category = ServiceCategory::create(['name' => 'Dashboard '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Dashboard Service',
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
            'service_price' => $overrides['total_price'] ?? 100,
            'commission_rate' => 10,
            'currency' => 'PHP',
        ], $overrides));
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: string}
     */
    private function actingAdministrator(array $permissions = ['view dashboard']): array
    {
        $user = User::factory()->create([
            'email' => 'dashboard-admin@skillserve.test',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
        $role = Role::findOrCreate('dashboard-admin');
        $role->syncPermissions(array_map(fn (string $permission) => Permission::findOrCreate($permission), $permissions));
        $user->assignRole($role);

        return [$user->createToken('test')->plainTextToken];
    }
}
