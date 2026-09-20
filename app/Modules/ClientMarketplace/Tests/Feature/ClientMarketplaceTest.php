<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientPreferences\Services\ClientPreferenceService;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
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
            // The catalog lists verified providers only, and says so, but it
            // still never exposes the owning user account.
            ->assertJsonPath('data.0.verification_status', 'verified')
            ->assertJsonMissingPath('data.0.user');
    }

    public function test_catalog_lists_include_card_summaries_from_public_services_only(): void
    {
        [$provider] = $this->provider();
        [$otherProvider] = $this->provider(['business_name' => 'Second Provider']);
        $cleaning = $this->category('Cleaning '.Str::random(5));
        $repair = $this->category('Repair '.Str::random(5));

        $this->service($provider, $cleaning, ['price' => 900]);
        $this->service($provider, $cleaning, ['price' => 1500]);
        $this->service($provider, $repair, ['price' => 700]);
        $this->service($provider, $repair, ['price' => 100, 'approval_status' => 'pending', 'status' => 'draft']);
        $this->service($otherProvider, $cleaning, ['price' => 2000]);

        $providers = collect($this->getJson('/api/client/v1/providers?per_page=10')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('700.00', $providers[$provider->id]['starting_price']);
        $this->assertSame($cleaning->name, $providers[$provider->id]['primary_category']);
        $this->assertSame('2000.00', $providers[$otherProvider->id]['starting_price']);

        $this->getJson("/api/client/v1/providers/{$provider->id}")
            ->assertOk()
            ->assertJsonPath('data.starting_price', '700.00')
            ->assertJsonPath('data.primary_category', $cleaning->name);

        $categories = collect($this->getJson('/api/client/v1/categories')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(2, $categories[$cleaning->id]['provider_count']);
        $this->assertSame(1, $categories[$repair->id]['provider_count']);
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

    public function test_a_booking_keeps_the_job_address_and_contact_number(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $service = $this->service($provider, $this->category('Booking '.Str::random(5)), ['currency' => 'PHP']);
        $token = $this->clientToken($client);
        $payload = [
            'service_id' => $service->id,
            'scheduled_date' => now()->addDay()->toISOString(),
            'service_address' => '12 Mabini St, Quezon City',
            'contact_phone' => '09171234567',
        ];

        $created = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'booking-address-1')
            ->postJson('/api/client/v1/bookings', $payload)
            ->assertCreated()
            ->assertJsonPath('data.service_address', '12 Mabini St, Quezon City')
            ->assertJsonPath('data.contact_phone', '09171234567');

        $this->assertDatabaseHas('bookings', [
            'id' => $created->json('data.id'),
            'service_address' => '12 Mabini St, Quezon City',
            'contact_phone' => '09171234567',
        ]);

        // A replayed key with a different address is a different request.
        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'booking-address-1')
            ->postJson('/api/client/v1/bookings', [...$payload, 'service_address' => 'Somewhere else'])
            ->assertStatus(409);

        $this->withToken($token)
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => now()->addDays(3)->toISOString(),
                'service_address' => str_repeat('a', 256),
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('bookings', 1);
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

    public function test_a_private_profile_is_hidden_from_public_discovery(): void
    {
        [$public] = $this->provider(['business_name' => 'Public Provider']);
        [$private, $privateUser] = $this->provider(['business_name' => 'Private Provider']);
        $category = $this->category('Cleaning '.Str::random(5));
        $this->service($public, $category);
        $privateService = $this->service($private, $category, ['title' => 'Hidden by preference']);

        // Both are discoverable while the setting is off.
        $this->getJson('/api/client/v1/providers?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);

        app(ClientPreferenceService::class)->update($privateUser, ['private_profile' => true]);

        // The provider drops out of the listing...
        $listed = collect($this->getJson('/api/client/v1/providers?per_page=10')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertTrue($listed->contains($public->id));
        $this->assertFalse($listed->contains($private->id), 'A private profile must not be listed.');

        // ...their detail page is gone...
        $this->getJson("/api/client/v1/providers/{$private->id}")->assertNotFound();

        // ...and their services leave the public catalogue with them.
        $services = collect($this->getJson('/api/client/v1/services?per_page=10')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertFalse($services->contains($privateService->id));

        // Switching it back restores discovery.
        app(ClientPreferenceService::class)->update($privateUser, ['private_profile' => false]);
        $this->getJson('/api/client/v1/providers?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_discovery_can_be_filtered_to_featured_providers(): void
    {
        [$featured] = $this->provider(['business_name' => 'Featured Co.', 'is_featured' => true]);
        [$ordinary] = $this->provider(['business_name' => 'Ordinary Co.']);
        $category = $this->category('Cleaning '.Str::random(5));
        $this->service($featured, $category);
        $this->service($ordinary, $category);

        $all = collect($this->getJson('/api/client/v1/providers?per_page=10')->assertOk()->json('data'));
        $this->assertCount(2, $all);

        $onlyFeatured = collect(
            $this->getJson('/api/client/v1/providers?featured=1&per_page=10')->assertOk()->json('data'),
        );
        $this->assertCount(1, $onlyFeatured);
        $this->assertSame($featured->id, $onlyFeatured->first()['id']);
        $this->assertTrue($onlyFeatured->first()['is_featured']);

        // The flag is also usable in reverse.
        $notFeatured = collect(
            $this->getJson('/api/client/v1/providers?featured=0&per_page=10')->assertOk()->json('data'),
        );
        $this->assertSame([$ordinary->id], $notFeatured->pluck('id')->all());
    }

    public function test_the_featured_filter_rejects_a_non_boolean_value(): void
    {
        $this->getJson('/api/client/v1/providers?featured=maybe')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['featured']);
    }

    public function test_discovery_can_be_filtered_by_rating_and_bookable_availability(): void
    {
        [$highlyRated] = $this->provider(['business_name' => 'Five Star Co.', 'average_rating' => 4.8]);
        [$lowRated] = $this->provider(['business_name' => 'Three Star Co.', 'average_rating' => 3.2]);
        [$quoteOnly] = $this->provider(['business_name' => 'Quote Only Co.', 'average_rating' => 5.0]);
        $category = $this->category('Cleaning '.Str::random(5));

        $this->service($highlyRated, $category, ['title' => 'Top rated clean', 'price' => 900, 'average_rating' => 4.8]);
        $this->service($lowRated, $category, ['title' => 'Budget clean', 'price' => 400, 'average_rating' => 3.2]);
        // Custom-priced work cannot be booked online, so its provider is not
        // "available" even though the service is published.
        $this->service($quoteOnly, $category, ['title' => 'Bespoke clean', 'price' => null, 'price_type' => 'custom']);

        $rated = collect($this->getJson('/api/client/v1/providers?min_rating=4.5&per_page=10')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$highlyRated->id, $quoteOnly->id], $rated->pluck('id')->all());

        $available = collect($this->getJson('/api/client/v1/providers?available=1&per_page=10')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$highlyRated->id, $lowRated->id], $available->pluck('id')->all());

        $unavailable = collect($this->getJson('/api/client/v1/providers?available=0&per_page=10')->assertOk()->json('data'));
        $this->assertSame([$quoteOnly->id], $unavailable->pluck('id')->all());

        $topRatedAndBookable = collect(
            $this->getJson('/api/client/v1/providers?min_rating=4.5&available=1&sort=average_rating&direction=desc&per_page=10')
                ->assertOk()
                ->json('data'),
        );
        $this->assertSame([$highlyRated->id], $topRatedAndBookable->pluck('id')->all());

        $services = collect($this->getJson('/api/client/v1/services?min_rating=4.5&per_page=10')->assertOk()->json('data'));
        $this->assertSame(['Top rated clean'], $services->pluck('title')->all());
    }

    public function test_the_discovery_filters_reject_out_of_range_values(): void
    {
        $this->getJson('/api/client/v1/providers?min_rating=9')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['min_rating']);

        $this->getJson('/api/client/v1/providers?available=sometimes')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['available']);

        $this->getJson('/api/client/v1/providers?available_day=9')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['available_day']);
    }

    public function test_availability_narrows_discovery_and_is_published_on_the_profile(): void
    {
        [$weekdayPro] = $this->provider(['business_name' => 'Weekday Co.']);
        [$weekendPro] = $this->provider(['business_name' => 'Weekend Co.']);
        [$paused] = $this->provider(['business_name' => 'Paused Co.', 'is_accepting_bookings' => false]);
        $category = $this->category('Cleaning '.Str::random(5));

        foreach ([$weekdayPro, $weekendPro, $paused] as $profile) {
            $this->service($profile, $category);
        }

        $weekdayPro->availabilities()->createMany([
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
        ]);
        $weekendPro->availabilities()->create([
            'day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);

        // A provider who is not taking bookings is not "available", even with
        // a bookable service.
        $available = collect($this->getJson('/api/client/v1/providers?available=1&per_page=10')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$weekdayPro->id, $weekendPro->id], $available->pluck('id')->all());

        $unavailable = collect($this->getJson('/api/client/v1/providers?available=0&per_page=10')->assertOk()->json('data'));
        $this->assertSame([$paused->id], $unavailable->pluck('id')->all());

        $saturday = collect($this->getJson('/api/client/v1/providers?available_day=6&per_page=10')->assertOk()->json('data'));
        $this->assertSame([$weekendPro->id], $saturday->pluck('id')->all());

        // The list stays lean; the windows come with the detail response.
        $listed = $available->firstWhere('id', $weekdayPro->id);
        $this->assertArrayNotHasKey('availability', $listed);
        $this->assertTrue($listed['is_accepting_bookings']);

        $detail = $this->getJson("/api/client/v1/providers/{$weekdayPro->id}")->assertOk()->json('data');
        $this->assertSame(
            [['day_of_week' => 1, 'day' => 'Monday', 'start_time' => '09:00', 'end_time' => '17:00'],
                ['day_of_week' => 2, 'day' => 'Tuesday', 'start_time' => '09:00', 'end_time' => '17:00']],
            $detail['availability'],
        );
    }

    public function test_bookings_respect_the_provider_schedule_and_the_accepting_flag(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $service = $this->service($provider, $this->category('Schedule '.Str::random(5)), [
            'duration' => '2 hours',
        ]);
        $token = $this->clientToken($client);

        // Next Monday, so the weekday is deterministic regardless of today.
        $monday = now()->addWeek()->startOfWeek();
        $provider->availabilities()->create([
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00',
        ]);

        $this->withToken($token)
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => $monday->copy()->setTime(16, 0)->toISOString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.scheduled_date.0', 'The provider works Mondays from 09:00 to 17:00.');

        $this->withToken($token)
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => $monday->copy()->addDay()->setTime(10, 0)->toISOString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.scheduled_date.0', 'The provider does not publish hours on Tuesdays.');

        $this->withToken($token)
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => $monday->copy()->setTime(10, 0)->toISOString(),
            ])
            ->assertCreated();

        // Pausing new bookings closes the door regardless of the schedule.
        $provider->update(['is_accepting_bookings' => false]);

        $this->withToken($token)
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => $monday->copy()->addWeek()->setTime(10, 0)->toISOString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.service_id.0', 'The provider is not accepting new bookings.');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_a_provider_without_published_hours_can_still_be_booked_at_any_time(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $service = $this->service($provider, $this->category('Anytime '.Str::random(5)));

        $this->withToken($this->clientToken($client))
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => now()->addWeek()->startOfWeek()->setTime(23, 0)->toISOString(),
            ])
            ->assertCreated();
    }

    public function test_a_public_provider_profile_carries_its_badges_and_portfolio(): void
    {
        [$provider, $providerUser] = $this->provider();
        $category = $this->category('Cleaning '.Str::random(5));
        $this->service($provider, $category);

        $badge = ProviderBadge::create([
            'name' => 'Top Rated',
            'slug' => 'top_rated',
            'description' => 'Maintain a high rating.',
            'color' => 'primary',
            'is_active' => true,
        ]);
        ProviderBadge::create(['name' => 'Retired', 'slug' => 'retired', 'is_active' => false]);
        $provider->badges()->attach($badge->id, ['assigned_at' => now()]);

        $provider->portfolioItems()->create([
            'title' => 'Deep clean',
            'description' => 'Three-bedroom condo.',
            'image_path' => 'portfolio/sample.jpg',
        ]);

        $detail = $this->getJson("/api/client/v1/providers/{$provider->id}")->assertOk()->json('data');

        $this->assertCount(1, $detail['badges']);
        $this->assertSame('top_rated', $detail['badges'][0]['key']);
        $this->assertTrue($detail['badges'][0]['earned']);

        $this->assertCount(1, $detail['portfolio']);
        $this->assertSame('Deep clean', $detail['portfolio'][0]['title']);
        $this->assertNotNull($detail['portfolio'][0]['image']);

        // The list stays lean: neither is loaded per row.
        $listed = $this->getJson('/api/client/v1/providers?per_page=10')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('badges', $listed);
        $this->assertArrayNotHasKey('portfolio', $listed);

        // A hidden provider exposes nothing, badges and portfolio included.
        app(ClientPreferenceService::class)->update($providerUser, ['private_profile' => true]);
        $this->getJson("/api/client/v1/providers/{$provider->id}")->assertNotFound();
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
