<?php

namespace App\Modules\Commissions\Tests\Feature;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Commissions\Services\CommissionTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommissionTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * An administrator holding [$permissions], and its bearer token.
     *
     * @param  array<int, string>  $permissions
     * @return array{0: User, 1: string}
     */
    private function actingAdministrator(array $permissions = ['manage commissions']): array
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $role = Role::findOrCreate('commission-manager');
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('commission-manager');

        return [$user, $user->createToken('test')->plainTextToken];
    }

    /** @param  array<string, mixed>  $attributes */
    private function tier(array $attributes = []): CommissionTier
    {
        return CommissionTier::create(array_merge([
            'name' => 'Standard',
            'min_amount' => 200,
            'max_amount' => 499.99,
            'percentage' => 10,
            'is_active' => true,
        ], $attributes));
    }

    public function test_listing_tiers_requires_authentication(): void
    {
        $this->getJson('/api/commission-tiers')->assertStatus(401);
    }

    public function test_listing_tiers_requires_the_view_permission(): void
    {
        [, $token] = $this->actingAdministrator(['view bookings']);

        $this->withToken($token)->getJson('/api/commission-tiers')->assertStatus(403);
    }

    public function test_view_permission_can_read_but_not_change_tiers(): void
    {
        [, $token] = $this->actingAdministrator(['view commissions']);
        $this->tier();

        $this->withToken($token)->getJson('/api/commission-tiers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Standard');

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Premium',
            'min_amount' => 500,
            'percentage' => 15,
        ])->assertStatus(403);
    }

    public function test_an_administrator_can_create_a_tier(): void
    {
        [$actor, $token] = $this->actingAdministrator();

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Standard',
            'min_amount' => 200,
            'max_amount' => 499.99,
            'percentage' => 10,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Standard')
            ->assertJsonPath('data.percentage', '10.00')
            ->assertJsonPath('data.is_open_ended', false);

        $this->assertDatabaseHas('commission_tiers', [
            'name' => 'Standard',
            'percentage' => 10,
            'created_by' => $actor->id,
            'is_active' => true,
        ]);
    }

    public function test_a_tier_without_a_maximum_is_open_ended(): void
    {
        [, $token] = $this->actingAdministrator();

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Top',
            'min_amount' => 1000,
            'percentage' => 20,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.max_amount', null)
            ->assertJsonPath('data.is_open_ended', true);
    }

    public function test_an_overlapping_range_is_refused(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->tier(['min_amount' => 200, 'max_amount' => 499.99]);

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Clashing',
            'min_amount' => 400,
            'max_amount' => 800,
            'percentage' => 12,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_amount');

        $this->assertDatabaseMissing('commission_tiers', ['name' => 'Clashing']);
    }

    public function test_a_second_open_ended_tier_is_refused(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->tier(['name' => 'Top', 'min_amount' => 1000, 'max_amount' => null]);

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Another top',
            'min_amount' => 5000,
            'percentage' => 25,
        ])->assertStatus(422)->assertJsonValidationErrors('min_amount');
    }

    public function test_a_range_may_overlap_a_disabled_tier(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->tier(['min_amount' => 200, 'max_amount' => 499.99, 'is_active' => false]);

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Replacement',
            'min_amount' => 200,
            'max_amount' => 499.99,
            'percentage' => 12,
        ])->assertStatus(201);
    }

    public function test_adjacent_ranges_are_allowed(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->tier(['min_amount' => 0, 'max_amount' => 199.99]);

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Standard',
            'min_amount' => 200,
            'max_amount' => 499.99,
            'percentage' => 10,
        ])->assertStatus(201);
    }

    public function test_a_maximum_below_the_minimum_is_refused(): void
    {
        [, $token] = $this->actingAdministrator();

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Backwards',
            'min_amount' => 500,
            'max_amount' => 100,
            'percentage' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('max_amount');
    }

    public function test_a_percentage_above_one_hundred_is_refused(): void
    {
        [, $token] = $this->actingAdministrator();

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Absurd',
            'min_amount' => 0,
            'percentage' => 120,
        ])->assertStatus(422)->assertJsonValidationErrors('percentage');
    }

    public function test_a_partial_update_validates_against_the_stored_bound(): void
    {
        [, $token] = $this->actingAdministrator();
        $tier = $this->tier(['min_amount' => 200, 'max_amount' => 499.99]);

        // max_amount alone, below the stored min_amount of 200.
        $this->withToken($token)->patchJson("/api/commission-tiers/{$tier->id}", [
            'max_amount' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('max_amount');
    }

    public function test_a_tier_can_be_updated_without_clashing_with_itself(): void
    {
        [, $token] = $this->actingAdministrator();
        $tier = $this->tier(['min_amount' => 200, 'max_amount' => 499.99]);

        $this->withToken($token)->patchJson("/api/commission-tiers/{$tier->id}", [
            'percentage' => 12.5,
        ])
            ->assertOk()
            ->assertJsonPath('data.percentage', '12.50');
    }

    public function test_updating_a_tier_into_another_range_is_refused(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->tier(['name' => 'Low', 'min_amount' => 0, 'max_amount' => 199.99]);
        $tier = $this->tier(['name' => 'Standard', 'min_amount' => 200, 'max_amount' => 499.99]);

        $this->withToken($token)->patchJson("/api/commission-tiers/{$tier->id}", [
            'min_amount' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('min_amount');
    }

    public function test_disabling_a_tier_lifts_the_overlap_rule(): void
    {
        [, $token] = $this->actingAdministrator();
        $this->tier(['name' => 'Low', 'min_amount' => 0, 'max_amount' => 199.99]);
        $tier = $this->tier(['name' => 'Standard', 'min_amount' => 200, 'max_amount' => 499.99]);

        $this->withToken($token)->patchJson("/api/commission-tiers/{$tier->id}", [
            'min_amount' => 100,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_a_tier_can_be_retired(): void
    {
        [, $token] = $this->actingAdministrator();
        $tier = $this->tier();

        $this->withToken($token)->deleteJson("/api/commission-tiers/{$tier->id}")->assertStatus(204);

        $this->assertSoftDeleted('commission_tiers', ['id' => $tier->id]);
    }

    public function test_a_retired_tier_frees_its_range(): void
    {
        [, $token] = $this->actingAdministrator();
        $tier = $this->tier(['min_amount' => 200, 'max_amount' => 499.99]);

        $this->withToken($token)->deleteJson("/api/commission-tiers/{$tier->id}")->assertStatus(204);

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Replacement',
            'min_amount' => 200,
            'max_amount' => 499.99,
            'percentage' => 12,
        ])->assertStatus(201);
    }

    public function test_every_change_is_written_to_the_audit_log(): void
    {
        [$actor, $token] = $this->actingAdministrator();

        $this->withToken($token)->postJson('/api/commission-tiers', [
            'name' => 'Standard',
            'min_amount' => 200,
            'max_amount' => 499.99,
            'percentage' => 10,
        ])->assertStatus(201);

        $activity = Activity::query()->where('log_name', 'commissions')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('commission_tier_created', $activity->description);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_resolving_picks_the_band_that_covers_the_amount(): void
    {
        $this->tier(['name' => 'Low', 'min_amount' => 0, 'max_amount' => 199.99, 'percentage' => 5]);
        $this->tier(['name' => 'Standard', 'min_amount' => 200, 'max_amount' => 499.99, 'percentage' => 10]);
        $this->tier(['name' => 'Top', 'min_amount' => 1000, 'max_amount' => null, 'percentage' => 20]);

        $service = app(CommissionTierService::class);

        $this->assertSame('Low', $service->resolve(199.99)->name);
        $this->assertSame('Standard', $service->resolve(200)->name);
        $this->assertSame('Standard', $service->resolve(499.99)->name);
        $this->assertSame('Top', $service->resolve(1000)->name);
        $this->assertSame('Top', $service->resolve(50000)->name);
    }

    public function test_resolving_an_uncovered_amount_returns_nothing(): void
    {
        $this->tier(['min_amount' => 200, 'max_amount' => 499.99]);

        $service = app(CommissionTierService::class);

        // 500–999.99 is a gap in this configuration.
        $this->assertNull($service->resolve(750));
    }

    public function test_resolving_ignores_disabled_tiers(): void
    {
        $this->tier(['min_amount' => 200, 'max_amount' => 499.99, 'is_active' => false]);

        $this->assertNull(app(CommissionTierService::class)->resolve(300));
    }
}
