<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Services\ClientSessionService;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Service Provider Account module: a provider maintaining their own
 * professional profile, portfolio and badges from the mobile app.
 */
class ProviderAccountTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ProviderProfile, 1: string} */
    private function provider(array $attributes = []): array
    {
        $user = User::factory()->create([
            'user_type' => 'provider',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $profile = ProviderProfile::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Dela Cruz Services',
            'specialization' => 'Plumbing',
            'experience_years' => 2,
            'verification_status' => 'verified',
        ], $attributes));

        return [$profile, app(ClientSessionService::class)->issue($user)['token']];
    }

    /** `fake()->image()` needs GD, which the runner does not always have. */
    private function fakeImage(string $name, int $kilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/jpeg');
    }

    public function test_a_provider_can_update_their_own_professional_profile(): void
    {
        [$profile, $token] = $this->provider();

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/profile', [
                'business_name' => 'Dela Cruz Plumbing Co.',
                'bio' => 'Licensed plumber serving Metro Manila.',
                'specialization' => 'Emergency plumbing',
                'experience_years' => 7,
                'hourly_rate' => 450,
                'location' => 'Quezon City',
                'languages' => ['English', 'Filipino'],
            ])
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Dela Cruz Plumbing Co.')
            ->assertJsonPath('data.specialization', 'Emergency plumbing')
            ->assertJsonPath('data.experience_years', 7)
            ->assertJsonPath('data.location', 'Quezon City');

        $this->assertSame('Emergency plumbing', $profile->fresh()->specialization);
    }

    public function test_a_partial_profile_update_leaves_other_fields_alone(): void
    {
        [$profile, $token] = $this->provider(['bio' => 'Original bio']);

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/profile', ['experience_years' => 9])
            ->assertOk()
            ->assertJsonPath('data.bio', 'Original bio')
            ->assertJsonPath('data.business_name', 'Dela Cruz Services');

        $this->assertSame(9, $profile->fresh()->experience_years);
    }

    public function test_a_provider_cannot_verify_or_feature_themselves(): void
    {
        [$profile, $token] = $this->provider(['verification_status' => 'pending']);

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/profile', [
                'bio' => 'Updated',
                'verification_status' => 'verified',
                'is_featured' => true,
                'average_rating' => 5,
                'total_bookings' => 999,
            ])
            ->assertOk();

        $profile->refresh();
        $this->assertSame('pending', $profile->verification_status);
        $this->assertFalse((bool) $profile->is_featured);
        $this->assertNotSame(999, $profile->total_bookings);
    }

    public function test_the_profile_update_is_validated(): void
    {
        [, $token] = $this->provider();

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/profile', [
                'specialization' => '',
                'experience_years' => 200,
                'website' => 'not-a-url',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['specialization', 'experience_years', 'website']);
    }

    public function test_a_provider_manages_their_own_portfolio(): void
    {
        Storage::fake('public');
        [$profile, $token] = $this->provider();

        // Empty to begin with.
        $this->withToken($token)
            ->getJson('/api/client/v1/provider/portfolio')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $created = $this->withToken($token)
            ->postJson('/api/client/v1/provider/portfolio', [
                'title' => 'Bathroom repipe',
                'description' => 'Full copper repipe in two days.',
                'image' => $this->fakeImage('job.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Bathroom repipe')
            ->assertJsonPath('data.provider_id', $profile->id)
            ->json('data');

        $this->assertNotNull($created['image'], 'The item must expose an image URL.');
        $this->assertDatabaseCount('provider_portfolio_items', 1);

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/portfolio')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.portfolio_id', $created['portfolio_id']);

        $this->withToken($token)
            ->deleteJson("/api/client/v1/provider/portfolio/{$created['portfolio_id']}")
            ->assertOk();

        $this->assertDatabaseCount('provider_portfolio_items', 0);
    }

    public function test_a_provider_cannot_delete_another_providers_portfolio_item(): void
    {
        Storage::fake('public');
        [, $ownerToken] = $this->provider();
        [, $otherToken] = $this->provider(['business_name' => 'Rival Services']);

        $itemId = $this->withToken($ownerToken)
            ->postJson('/api/client/v1/provider/portfolio', [
                'title' => 'Mine',
                'image' => $this->fakeImage('mine.jpg'),
            ])
            ->assertCreated()
            ->json('data.portfolio_id');

        // The guard caches the resolved user between calls in a single test.
        Auth::forgetGuards();

        $this->withToken($otherToken)
            ->deleteJson("/api/client/v1/provider/portfolio/{$itemId}")
            ->assertNotFound();

        $this->assertDatabaseCount('provider_portfolio_items', 1);
    }

    public function test_the_portfolio_upload_rejects_non_images_and_requires_a_title(): void
    {
        Storage::fake('public');
        [, $token] = $this->provider();

        $this->withToken($token)
            ->postJson('/api/client/v1/provider/portfolio', [
                'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'image']);

        $this->assertDatabaseCount('provider_portfolio_items', 0);
    }

    public function test_badges_list_what_is_earned_and_what_is_still_available(): void
    {
        [$profile, $token] = $this->provider();

        $earned = ProviderBadge::create([
            'name' => 'Top Rated',
            'slug' => 'top_rated',
            'description' => 'Maintain a 4.8 rating over 20 jobs.',
            'color' => 'primary',
            'is_active' => true,
        ]);
        ProviderBadge::create([
            'name' => 'Veteran',
            'slug' => 'veteran',
            'description' => 'Two years on SkillServe.',
            'color' => 'neutral',
            'is_active' => true,
        ]);
        // Retired badges are shown to nobody.
        ProviderBadge::create([
            'name' => 'Legacy',
            'slug' => 'legacy',
            'is_active' => false,
        ]);

        $profile->badges()->attach($earned->id, ['assigned_at' => now()]);

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/badges')
            ->assertOk()
            ->assertJsonCount(1, 'data.earned')
            ->assertJsonPath('data.earned.0.key', 'top_rated')
            ->assertJsonPath('data.earned.0.earned', true)
            ->assertJsonCount(1, 'data.available')
            ->assertJsonPath('data.available.0.key', 'veteran')
            ->assertJsonPath('data.available.0.earned', false);
    }

    public function test_a_provider_publishes_and_replaces_their_weekly_hours(): void
    {
        [$profile, $token] = $this->provider();

        $this->withToken($token)
            ->getJson('/api/client/v1/provider/availability')
            ->assertOk()
            ->assertJsonPath('data.is_accepting_bookings', true)
            ->assertJsonCount(0, 'data.availability');

        $this->withToken($token)
            ->putJson('/api/client/v1/provider/availability', [
                'is_accepting_bookings' => false,
                'availability' => [
                    ['day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '18:00'],
                    ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.is_accepting_bookings', false)
            // Returned in week order, not the order they were sent.
            ->assertJsonPath('data.availability.0.day', 'Monday')
            ->assertJsonPath('data.availability.0.start_time', '09:00')
            ->assertJsonPath('data.availability.1.day', 'Wednesday')
            ->assertJsonPath('data.availability.1.end_time', '18:00');

        $this->assertSame(2, $profile->availabilities()->count());
        $this->assertFalse($profile->fresh()->is_accepting_bookings);

        // Sending the schedule again replaces it wholesale.
        $this->withToken($token)
            ->putJson('/api/client/v1/provider/availability', [
                'availability' => [['day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '12:00']],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.availability')
            ->assertJsonPath('data.availability.0.day', 'Saturday')
            // The flag was not sent, so it keeps its value.
            ->assertJsonPath('data.is_accepting_bookings', false);

        // And an empty array clears it.
        $this->withToken($token)
            ->putJson('/api/client/v1/provider/availability', ['availability' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.availability');

        $this->assertSame(0, $profile->availabilities()->count());
    }

    public function test_an_invalid_schedule_is_rejected_before_it_reaches_the_database(): void
    {
        [$profile, $token] = $this->provider();

        $this->withToken($token)
            ->putJson('/api/client/v1/provider/availability', [
                'availability' => [
                    ['day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '09:00'],
                    ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00'],
                    ['day_of_week' => 9, 'start_time' => '09:00', 'end_time' => '10:00'],
                    ['day_of_week' => 2, 'start_time' => 'morning', 'end_time' => '10:00'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'availability.0.end_time',
                'availability.1.day_of_week',
                'availability.2.day_of_week',
                'availability.3.start_time',
            ]);

        $this->assertSame(0, $profile->availabilities()->count());
    }

    public function test_the_provider_account_endpoints_reject_anonymous_callers(): void
    {
        foreach ($this->providerEndpoints() as [$method, $path]) {
            $this->$method($path)->assertStatus(401);
        }
    }

    public function test_the_provider_account_endpoints_reject_customer_accounts(): void
    {
        $customer = User::factory()->create([
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $token = app(ClientSessionService::class)->issue($customer)['token'];

        foreach ($this->providerEndpoints() as [$method, $path]) {
            // The guard caches the resolved user between calls in one test.
            Auth::forgetGuards();
            $this->withToken($token)->$method($path)->assertStatus(403);
        }
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function providerEndpoints(): array
    {
        return [
            ['getJson', '/api/client/v1/provider/profile'],
            ['patchJson', '/api/client/v1/provider/profile'],
            ['getJson', '/api/client/v1/provider/portfolio'],
            ['postJson', '/api/client/v1/provider/portfolio'],
            ['getJson', '/api/client/v1/provider/availability'],
            ['putJson', '/api/client/v1/provider/availability'],
            ['getJson', '/api/client/v1/provider/badges'],
        ];
    }
}
