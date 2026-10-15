<?php

namespace App\Modules\Providers\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingRefund;
use App\Modules\ClientAuthentication\Events\ClientAccountRestricted;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Suspending a provider locks their account and signs them out at once;
 * DELETE /api/providers/{provider} removes the account once nothing is owed.
 */
class ProviderAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Event::fake([ClientAccountRestricted::class]);
    }

    public function test_suspending_a_provider_suspends_the_account_and_signs_them_out(): void
    {
        $provider = $this->provider();
        $provider->user->createToken('app', ['client:auth']);

        $this->withToken($this->adminToken(['view providers', 'suspend providers']))
            ->patchJson("/api/providers/{$provider->id}/suspend", ['reason' => 'Took payment and never showed up.'])
            ->assertOk();

        $user = $provider->user->fresh();
        $this->assertSame('suspended', $user->status);
        $this->assertSame('Took payment and never showed up.', $user->suspension_reason);
        $this->assertSame(0, $user->tokens()->count());
        Event::assertDispatched(ClientAccountRestricted::class, fn (ClientAccountRestricted $event) => $event->userId === $user->id
            && $event->account['status'] === 'suspended'
            && $event->account['reason'] === 'Took payment and never showed up.'
            && $event->message === 'Your account is suspended.');

        $this->postJson('/api/client/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('meta.account.status', 'suspended');
    }

    public function test_activating_a_provider_reactivates_the_account(): void
    {
        $provider = $this->provider();
        $this->withToken($this->adminToken(['view providers', 'suspend providers', 'activate providers']))
            ->patchJson("/api/providers/{$provider->id}/suspend", ['reason' => 'Fraud review.'])
            ->assertOk();

        $this->patchJson("/api/providers/{$provider->id}/activate")->assertOk();

        $this->assertNull($provider->fresh()->suspended_at);
        $this->assertSame('active', $provider->user->fresh()->status);
    }

    public function test_a_banned_providers_account_stays_banned_through_suspend_and_activate(): void
    {
        $provider = $this->provider(['status' => 'banned', 'banned_at' => now(), 'ban_reason' => 'Scam.']);
        $token = $this->adminToken(['view providers', 'suspend providers', 'activate providers']);

        $this->withToken($token)->patchJson("/api/providers/{$provider->id}/suspend", ['reason' => 'Also suspended.'])->assertOk();
        $this->assertSame('banned', $provider->user->fresh()->status);

        $this->withToken($token)->patchJson("/api/providers/{$provider->id}/activate")->assertOk();
        $this->assertNull($provider->fresh()->suspended_at);
        $this->assertSame('banned', $provider->user->fresh()->status);
    }

    public function test_deleting_a_provider_removes_the_account_and_signs_them_out(): void
    {
        $provider = $this->provider();
        $provider->user->createToken('app', ['client:auth']);
        $token = $this->adminToken(['view providers', 'delete providers']);

        $this->withToken($token)
            ->deleteJson("/api/providers/{$provider->id}", ['reason' => 'Breaks the community guidelines.'])
            ->assertOk()
            ->assertJsonPath('message', 'Provider deleted.');

        $user = User::withTrashed()->find($provider->user_id);
        $this->assertTrue($user->trashed());
        $this->assertNotNull($user->deleted_by);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('provider_profiles', ['id' => $provider->id]);
        Event::assertDispatched(ClientAccountRestricted::class, fn (ClientAccountRestricted $event) => $event->userId === $user->id
            && $event->account['status'] === 'deleted');
        $this->assertDatabaseHas('activity_log', ['description' => 'provider_deleted', 'subject_id' => $provider->id]);

        $this->withToken($token)->getJson('/api/providers')
            ->assertOk()
            ->assertJsonMissing(['id' => $provider->id, 'business_name' => 'Lifecycle Plumbing']);

        $this->withToken($token)->deleteJson("/api/providers/{$provider->id}", ['reason' => 'Breaks the community guidelines.'])->assertUnprocessable();
    }

    public function test_a_provider_with_an_open_booking_cannot_be_deleted(): void
    {
        $provider = $this->provider();
        $this->booking($provider, ['status' => 'confirmed']);

        $this->withToken($this->adminToken(['view providers', 'delete providers']))
            ->deleteJson("/api/providers/{$provider->id}", ['reason' => 'Breaks the community guidelines.'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This provider cannot be deleted while they have open bookings.');

        $this->assertFalse($provider->user->fresh()->trashed());
        Event::assertNotDispatched(ClientAccountRestricted::class);
    }

    public function test_a_provider_who_owes_a_customer_a_downpayment_cannot_be_deleted(): void
    {
        $provider = $this->provider();
        $booking = $this->booking($provider, ['status' => 'cancelled']);
        BookingRefund::create(['booking_id' => $booking->id, 'amount' => 500, 'status' => 'due', 'due_by' => now()->addDay()]);

        $this->withToken($this->adminToken(['view providers', 'delete providers']))
            ->deleteJson("/api/providers/{$provider->id}", ['reason' => 'Breaks the community guidelines.'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This provider cannot be deleted while they have a downpayment not yet returned to a customer.');
    }

    public function test_a_provider_with_unpaid_commission_cannot_be_deleted(): void
    {
        $provider = $this->provider();
        $this->booking($provider, ['status' => 'completed', 'payment_status' => 'paid', 'paid_at' => now(), 'commission_status' => 'outstanding']);

        $this->withToken($this->adminToken(['view providers', 'delete providers']))
            ->deleteJson("/api/providers/{$provider->id}", ['reason' => 'Breaks the community guidelines.'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This provider cannot be deleted while they have unpaid commission.');
    }

    public function test_deleting_a_provider_needs_the_delete_providers_permission(): void
    {
        $provider = $this->provider();

        $this->deleteJson("/api/providers/{$provider->id}", ['reason' => 'Breaks the community guidelines.'])->assertUnauthorized();

        $this->withToken($this->adminToken(['view providers', 'suspend providers']))
            ->deleteJson("/api/providers/{$provider->id}", ['reason' => 'Breaks the community guidelines.'])
            ->assertForbidden();

        $this->assertFalse($provider->user->fresh()->trashed());
    }

    /** @param  array<int, string>  $permissions */
    private function adminToken(array $permissions): string
    {
        foreach (['manage providers', 'view providers', 'suspend providers', 'activate providers', 'delete providers'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::create(['name' => 'providers-'.Str::random(6)]);
        $role->syncPermissions($permissions);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('test')->plainTextToken;
    }

    private function provider(array $attributes = []): ProviderProfile
    {
        $user = User::factory()->create(array_merge([
            'user_type' => 'provider',
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));

        return ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Lifecycle Plumbing',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    private function booking(ProviderProfile $provider, array $attributes): Booking
    {
        $category = ServiceCategory::create(['name' => 'Plumbing '.Str::random(5), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Pipe Repair',
            'price' => 1500,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        return Booking::create(array_merge([
            'service_id' => $service->id,
            'client_id' => User::factory()->create(['user_type' => 'customer', 'status' => 'active'])->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-DEL-'.Str::random(10),
            'payment_status' => 'unpaid',
            'total_price' => 1500,
            'service_price' => 1500,
            'platform_fee' => 150,
            'currency' => 'PHP',
            'scheduled_date' => now()->addDay(),
            'scheduled_end_date' => now()->addDay()->addHours(2),
        ], $attributes));
    }
}
