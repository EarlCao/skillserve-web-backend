<?php

namespace App\Modules\Bookings\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DisputeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function actingManager(array $permissions = ['view bookings', 'manage booking disputes']): array
    {
        foreach (array_merge(['manage bookings', 'view bookings', 'manage booking disputes'], $permissions) as $permission) {
            Permission::findOrCreate($permission);
        }

        $role = Role::findOrCreate('dispute-manager');
        $role->syncPermissions($permissions);
        $user = User::factory()->create([
            'email' => 'dispute-manager@skillserve.test',
            'password' => Hash::make('password123'),
            'name' => 'Dispute Manager',
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function createBooking(string $disputeStatus = 'pending'): Booking
    {
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'Dispute Provider', 'verification_status' => 'verified']);
        $category = ServiceCategory::create(['name' => 'Dispute Category '.uniqid(), 'status' => 'enabled']);
        $service = Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'Dispute Service', 'status' => 'published', 'approval_status' => 'approved']);

        return Booking::create([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-DISPUTE-'.uniqid(),
            'status' => 'disputed',
            'payment_status' => 'paid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'USD',
            'client_notes' => 'Client statement.',
            'provider_notes' => 'Provider statement.',
            'dispute_reason' => 'The service was not completed.',
            'disputed_at' => now(),
            'dispute_status' => $disputeStatus,
            'dispute_evidence' => [['label' => 'Screenshot', 'content' => 'evidence.jpg']],
        ]);
    }

    public function test_dispute_list_returns_paginated_disputes(): void
    {
        [, $token] = $this->actingManager();
        $booking = $this->createBooking();

        $this->withToken($token)
            ->getJson('/api/disputes?search='.$booking->booking_number.'&status=pending')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.booking_number', $booking->booking_number)
            ->assertJsonPath('data.0.dispute_evidence.0.label', 'Screenshot');
    }

    public function test_notes_history_resolution_and_close_work(): void
    {
        [, $token] = $this->actingManager();
        $booking = $this->createBooking();

        $this->withToken($token)->patchJson("/api/disputes/{$booking->id}/investigate")->assertOk()->assertJsonPath('data.dispute_status', 'investigated');
        $this->withToken($token)->patchJson("/api/disputes/{$booking->id}/notes", ['note' => 'Reviewed both statements.'])->assertOk()->assertJsonPath('data.dispute_notes.0.note', 'Reviewed both statements.');
        $this->withToken($token)->patchJson("/api/disputes/{$booking->id}/resolve", ['resolution' => 'Partial refund approved.'])->assertOk()->assertJsonPath('data.dispute_status', 'resolved');
        $this->withToken($token)->patchJson("/api/disputes/{$booking->id}/close", ['note' => 'Case archived.'])->assertOk()->assertJsonPath('data.dispute_status', 'closed');

        $this->withToken($token)
            ->getJson("/api/disputes/{$booking->id}/history?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 5)
            ->assertJsonPath('meta.pagination.per_page', 2);
    }

    public function test_close_requires_a_resolved_dispute(): void
    {
        [, $token] = $this->actingManager();
        $booking = $this->createBooking();

        $this->withToken($token)
            ->patchJson("/api/disputes/{$booking->id}/close")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['dispute_status']]);
    }

    public function test_view_only_manager_cannot_change_a_dispute(): void
    {
        [, $token] = $this->actingManager(['view bookings']);
        $booking = $this->createBooking();

        $this->withToken($token)
            ->patchJson("/api/disputes/{$booking->id}/investigate")
            ->assertStatus(403);
    }

    public function test_view_only_manager_can_view_details_and_history(): void
    {
        [, $token] = $this->actingManager(['view bookings']);
        $booking = $this->createBooking();

        $this->withToken($token)->getJson("/api/disputes/{$booking->id}")->assertOk();
        $this->withToken($token)->getJson("/api/disputes/{$booking->id}/history")->assertOk();
    }

    public function test_resolution_requires_a_decision(): void
    {
        [, $token] = $this->actingManager();
        $booking = $this->createBooking('investigated');

        $this->withToken($token)
            ->patchJson("/api/disputes/{$booking->id}/resolve", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['resolution']]);
    }
}
