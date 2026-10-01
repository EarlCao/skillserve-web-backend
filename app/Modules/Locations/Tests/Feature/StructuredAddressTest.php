<?php

namespace App\Modules\Locations\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\Locations\Tests\SeedsPhLocations;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Structured addresses where the app enters them: sign-up, the profile, a
 * booking's service address and a service's location. The client sends the
 * most specific place; the server derives the rest and keeps a formatted
 * copy in the text column older clients and the admin web read.
 */
class StructuredAddressTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhLocations;

    private const QC_ADDRESS = '123 Rizal St, Bagong Pag-asa, Quezon City, Metro Manila 1105';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPhLocations();
    }

    public function test_sign_up_carries_the_birthday_and_address_to_the_new_account(): void
    {
        Notification::fake();

        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'birthday' => '1995-04-02',
            'address_details' => ['barangay_code' => '137404009', 'street' => '123 Rizal St', 'postal_code' => '1105'],
        ])->assertStatus(202);

        PendingRegistration::query()->where('email', 'juan@example.com')->firstOrFail()->forceFill([
            'email_otp_hash' => Hash::make('123456'),
            'email_otp_expires_at' => now()->addMinutes(10),
        ])->save();

        $this->postJson('/api/client/v1/auth/verify-otp', ['email' => 'juan@example.com', 'code' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.user.address', self::QC_ADDRESS)
            ->assertJsonPath('data.user.birthday', '1995-04-02')
            ->assertJsonPath('data.user.address_details.region.code', '130000000')
            ->assertJsonPath('data.user.address_details.province', null)
            ->assertJsonPath('data.user.address_details.city.name', 'Quezon City')
            ->assertJsonPath('data.user.address_details.barangay.name', 'Bagong Pag-asa')
            ->assertJsonPath('data.user.address_details.postal_code', '1105');

        $this->assertDatabaseHas('users', [
            'email' => 'juan@example.com',
            'address_city_code' => '137404000',
            'address_barangay_code' => '137404009',
        ]);
    }

    public function test_google_sign_up_carries_the_birthday_and_address_too(): void
    {
        config(['services.google.client_id' => 'test-web-client-id.apps.googleusercontent.com']);
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response([
            'aud' => 'test-web-client-id.apps.googleusercontent.com',
            'sub' => 'google-sub-ph', 'email' => 'maria@gmail.com', 'email_verified' => 'true',
            'exp' => (string) (time() + 3600), 'given_name' => 'Maria', 'family_name' => 'Santos',
        ])]);

        $this->postJson('/api/client/v1/auth/google/register', [
            'id_token' => str_repeat('a', 30),
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'role' => 'customer',
            'birthday' => '1990-12-25',
            'address_details' => ['barangay_code' => '045805015', 'street' => 'Blk 5 Lot 2'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.address', 'Blk 5 Lot 2, San Isidro, Cainta, Rizal')
            ->assertJsonPath('data.user.birthday', '1990-12-25')
            ->assertJsonPath('data.user.address_details.city.code', '045805000');
    }

    public function test_an_unknown_place_or_a_bad_zip_is_refused_at_sign_up(): void
    {
        Notification::fake();
        $base = [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'juan@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ];

        $this->postJson('/api/client/v1/auth/register', [...$base, 'address_details' => ['barangay_code' => '999999999']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_details.barangay_code');

        $this->postJson('/api/client/v1/auth/register', [...$base, 'address_details' => ['barangay_code' => '137404009', 'postal_code' => '11O5']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_details.postal_code');
    }

    public function test_the_profile_address_is_picked_and_a_plain_text_edit_clears_the_codes(): void
    {
        $user = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $token = $user->createToken('client', ['client:auth'])->plainTextToken;

        $this->withToken($token)->patchJson('/api/client/v1/auth/me', [
            'address_details' => ['barangay_code' => '043428002', 'street' => 'Purok 3'],
        ])
            ->assertOk()
            ->assertJsonPath('data.address', 'Purok 3, Balibago, City of Santa Rosa, Laguna')
            ->assertJsonPath('data.address_details.province.name', 'Laguna');

        // An older app version sends text; the codes no longer describe it.
        $this->withToken($token)->patchJson('/api/client/v1/auth/me', ['address' => 'Somewhere else'])
            ->assertOk()
            ->assertJsonPath('data.address', 'Somewhere else')
            ->assertJsonPath('data.address_details', null);
    }

    public function test_a_booking_keeps_its_structured_service_address_for_both_sides(): void
    {
        [$service, $providerUser] = $this->bookableService();
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $bookingId = $this->withToken($customer->createToken('client', ['client:auth'])->plainTextToken)
            ->postJson('/api/client/v1/bookings', [
                'service_id' => $service->id,
                'scheduled_date' => now()->addDays(3)->setTime(10, 0)->toIso8601String(),
                'service_address_details' => ['barangay_code' => '137404009', 'street' => '123 Rizal St', 'postal_code' => '1105'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.service_address', self::QC_ADDRESS)
            ->assertJsonPath('data.service_address_details.barangay.code', '137404009')
            ->json('data.id');

        $this->assertSame('137404000', Booking::query()->findOrFail($bookingId)->service_city_code);

        $this->app['auth']->forgetGuards();
        $this->withToken($providerUser->createToken('provider', ['client:auth'])->plainTextToken)
            ->getJson("/api/client/v1/provider/bookings/{$bookingId}")
            ->assertOk()
            ->assertJsonPath('data.service_address', self::QC_ADDRESS)
            ->assertJsonPath('data.service_address_details.city.name', 'Quezon City');
    }

    public function test_a_service_area_is_a_city_with_an_optional_barangay_in_it(): void
    {
        [, $providerUser, $category] = $this->bookableService();
        $token = $providerUser->createToken('provider', ['client:auth'])->plainTextToken;
        $payload = ['title' => 'Aircon Cleaning', 'category_id' => $category->id, 'price' => 500, 'price_type' => 'fixed', 'duration' => '2 hours'];

        $this->withToken($token)->postJson('/api/client/v1/provider/services', [...$payload, 'location_details' => ['city_code' => '045805000']])
            ->assertCreated()
            ->assertJsonPath('data.location', 'Cainta, Rizal')
            ->assertJsonPath('data.location_details.city.code', '045805000')
            ->assertJsonPath('data.location_details.barangay', null);

        // San Isidro in Quezon City is not in Cainta.
        $this->withToken($token)->postJson('/api/client/v1/provider/services', [...$payload, 'location_details' => ['city_code' => '045805000', 'barangay_code' => '137404098']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location_details.barangay_code');

        $this->withToken($token)->postJson('/api/client/v1/provider/services', [...$payload, 'location_details' => ['city_code' => '137404009']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location_details.city_code');
    }

    /** @return array{0: Service, 1: User, 2: ServiceCategory} */
    private function bookableService(): array
    {
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Juan Aircon Services',
            'verification_status' => 'verified',
            'is_accepting_bookings' => true,
        ]);
        $category = ServiceCategory::create(['name' => 'Appliance Repair', 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Aircon Cleaning',
            'price' => 500,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'duration' => '2 hours',
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        return [$service, $providerUser, $category];
    }
}
