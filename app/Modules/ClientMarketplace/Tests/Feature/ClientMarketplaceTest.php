<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_only_exposes_enabled_catalog_and_active_verified_providers(): void
    {
        [$provider] = $this->provider();
        $category = $this->category('Visible '.Str::random(5));
        $hiddenCategory = $this->category('Hidden '.Str::random(5), 'disabled');
        $subcategory = ServiceSubcategory::create([
            'category_id' => $category->id,
            'name' => 'Visible subcategory',
            'status' => 'enabled',
        ]);
        ServiceSubcategory::create([
            'category_id' => $category->id,
            'name' => 'Hidden subcategory',
            'status' => 'disabled',
        ]);

        $visible = $this->service($provider, $category, [
            'subcategory_id' => $subcategory->id,
            'title' => 'Visible service',
        ]);
        $this->service($provider, $hiddenCategory, ['title' => 'Hidden category service']);
        $this->service($provider, $category, ['title' => 'Hidden service', 'is_hidden' => true]);

        $this->getJson('/api/client/v1/categories')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.subcategories.0.name', 'Visible subcategory')
            ->assertJsonMissing(['status' => 'disabled']);

        $this->getJson('/api/client/v1/services')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonMissingPath('data.0.approval_status')
            ->assertJsonMissingPath('data.0.provider.user');

        $this->getJson('/api/client/v1/providers')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $provider->id)
            ->assertJsonMissingPath('data.0.verification_status')
            ->assertJsonMissingPath('data.0.user');
    }

    public function test_admin_tokens_cannot_use_client_bookings_and_clients_cannot_read_each_other(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        [$provider] = $this->provider();
        $service = $this->service($provider, $this->category('Ownership '.Str::random(5)));
        $booking = $this->booking($owner, $service);
        $adminToken = $this->adminToken();

        $this->withToken($adminToken)
            ->getJson('/api/client/v1/bookings')
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($this->clientToken($other))
            ->getJson("/api/client/v1/bookings/{$booking->id}")
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($this->clientToken($owner))
            ->getJson('/api/client/v1/bookings')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.booking_number', $booking->booking_number)
            ->assertJsonMissingPath('data.0.provider_notes')
            ->assertJsonMissingPath('data.0.platform_fee');

        Auth::forgetGuards();
        $this->withToken($this->clientToken($other))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/cancel", ['reason' => 'Not mine'])
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($this->clientToken($owner))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/cancel", ['reason' => 'Plans changed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_booking_creation_derives_provider_and_price_and_replays_idempotently(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $service = $this->service($provider, $this->category('Booking '.Str::random(5)), [
            'price' => 125,
            'currency' => 'PHP',
        ]);
        $token = $this->clientToken($client);
        $payload = [
            'service_id' => $service->id,
            'scheduled_date' => now()->addDay()->toISOString(),
            'provider_id' => 999999,
            'total_price' => 1,
            'platform_fee' => 0,
            'currency' => 'USD',
        ];

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'booking-key-1')
            ->postJson('/api/client/v1/bookings', $payload)
            ->assertCreated()
            ->assertJsonPath('data.service_price', '125.00')
            ->assertJsonPath('data.total_price', '125.00')
            ->assertJsonPath('data.currency', 'PHP');

        $this->assertDatabaseHas('bookings', ['client_idempotency_key' => 'booking-key-1']);

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'booking-key-1')
            ->postJson('/api/client/v1/bookings', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'booking-key-1')
            ->postJson('/api/client/v1/bookings', $payload + ['client_notes' => 'Different request'])
            ->assertStatus(409);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'id' => $first->json('data.id'),
            'provider_id' => $provider->id,
            'service_price' => 125,
            'platform_fee' => 12.5,
            'client_idempotency_key' => 'booking-key-1',
        ]);
    }

    public function test_booking_creation_rejects_an_overlapping_provider_window(): void
    {
        $firstClient = $this->customer();
        $secondClient = $this->customer();
        [$provider] = $this->provider();
        $service = $this->service($provider, $this->category('Overlap '.Str::random(5)), [
            'duration' => '2 hours',
        ]);
        $start = now()->addDays(2)->startOfHour();

        $this->withToken($this->clientToken($firstClient))
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => $start->toISOString(),
            ])
            ->assertCreated();

        $this->withToken($this->clientToken($secondClient))
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => $start->copy()->addHour()->toISOString(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('errors.scheduled_date.0', 'The requested time overlaps another booking for this provider.');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_client_booking_payment_method_is_enum_and_cancellation_does_not_claim_a_refund(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $service = $this->service($provider, $this->category('Payment '.Str::random(5)));
        $token = $this->clientToken($client);
        $payload = [
            'service_id' => $service->id,
            'scheduled_date' => now()->addDays(3)->toISOString(),
            'payment_method' => 'not-a-payment-method',
        ];

        $this->withToken($token)
            ->postJson('/api/client/v1/bookings', $payload)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['payment_method']]);

        $booking = $this->withToken($token)
            ->postJson('/api/client/v1/bookings', [...$payload, 'payment_method' => 'credit_card'])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->json('data');

        $this->withToken($token)
            ->patchJson('/api/client/v1/bookings/'.$booking['id'].'/cancel')
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.cancellation_payment_policy', 'unpaid_no_refund_due');

        $paidBooking = Booking::query()->findOrFail($booking['id']);
        $paidBooking->update([
            'status' => 'pending',
            'payment_status' => 'paid',
            'scheduled_date' => now()->addDays(4),
            'scheduled_end_date' => now()->addDays(4)->addHour(),
        ]);

        $this->withToken($token)
            ->patchJson('/api/client/v1/bookings/'.$paidBooking->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.cancellation_payment_policy', 'payment_unchanged_refund_not_processed');
    }

    public function test_only_completed_owned_bookings_can_be_reviewed_and_aggregates_update_transactionally(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        [$provider] = $this->provider();
        $service = $this->service($provider, $this->category('Review '.Str::random(5)));
        $booking = $this->booking($owner, $service, ['status' => 'completed']);

        $otherResponse = $this->withToken($this->clientToken($other))
            ->postJson('/api/client/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
            ]);
        $otherResponse->assertNotFound();

        Auth::forgetGuards();
        $created = $this->withToken($this->clientToken($owner))
            ->postJson('/api/client/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 4,
                'comment' => 'Good service.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 4);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'is_reviewed' => true]);
        $this->assertSame('4.00', (string) $service->fresh()->average_rating);
        $this->assertSame(1, $provider->fresh()->total_reviews);

        Auth::forgetGuards();
        $this->withToken($this->clientToken($owner))
            ->patchJson('/api/client/v1/reviews/'.$created->json('data.id'), [
                'rating' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.rating', 5);

        $this->assertSame('5.00', (string) $service->fresh()->average_rating);
        $this->assertSame(1, Review::query()->where('reviewer_id', $owner->id)->count());
    }

    private function customer(): User
    {
        return User::factory()->create([
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    private function clientToken(User $client): string
    {
        return $client->createToken('client-test', ['client:auth'])->plainTextToken;
    }

    private function adminToken(): string
    {
        $role = Role::findOrCreate('marketplace-admin');
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        return $admin->createToken('admin-test', ['admin:auth'])->plainTextToken;
    }

    private function provider(array $attributes = []): array
    {
        $providerUser = User::factory()->create([
            'user_type' => 'provider',
            'status' => 'active',
        ]);
        $provider = ProviderProfile::create(array_merge([
            'user_id' => $providerUser->id,
            'business_name' => 'Verified Provider',
            'verification_status' => 'verified',
        ], $attributes));

        return [$provider, $providerUser];
    }

    private function category(string $name, string $status = 'enabled'): ServiceCategory
    {
        return ServiceCategory::create(['name' => $name, 'status' => $status]);
    }

    private function service(ProviderProfile $provider, ServiceCategory $category, array $attributes = []): Service
    {
        return Service::create(array_merge([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Bookable service',
            'price' => 100,
            'price_type' => 'fixed',
            'currency' => 'USD',
            'status' => 'published',
            'approval_status' => 'approved',
            'is_hidden' => false,
        ], $attributes));
    }

    private function booking(User $client, Service $service, array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $service->provider_id,
            'booking_number' => 'BK-TEST-'.Str::random(12),
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'total_price' => $service->price,
            'service_price' => $service->price,
            'platform_fee' => 10,
            'currency' => $service->currency,
            'scheduled_date' => now()->addDay(),
            'scheduled_end_date' => now()->addDay()->addHour(),
        ], $attributes));
    }
}
