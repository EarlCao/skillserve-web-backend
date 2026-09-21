<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_export_carries_the_accounts_own_data(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider, ['status' => 'completed']);

        $data = $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/auth/me/data-export')
            ->assertOk()
            ->assertJsonPath('data.account.email', $client->email)
            ->assertJsonPath('data.account.id', $client->id)
            ->json('data');

        $this->assertNotNull($data['exported_at']);
        $this->assertArrayHasKey('booking_notifications', $data['preferences']);
        // A customer has no provider profile.
        $this->assertNull($data['provider_profile']);
        $this->assertCount(1, $data['bookings']);
        $this->assertSame($booking->booking_number, $data['bookings'][0]['booking_number']);
        $this->assertSame([], $data['reviews']);
        $this->assertSame([], $data['reports_filed']);
        $this->assertSame([], $data['support_tickets']);

        // The export must never carry the password or other people's data.
        $this->assertArrayNotHasKey('password', $data['account']);
    }

    public function test_a_providers_export_includes_their_professional_profile(): void
    {
        [, $providerUser] = $this->provider();

        $this->withToken($providerUser->createToken('provider', ['client:auth'])->plainTextToken)
            ->getJson('/api/client/v1/auth/me/data-export')
            ->assertOk()
            ->assertJsonPath('data.provider_profile.business_name', 'Exported Provider');
    }

    public function test_an_export_shows_only_the_callers_own_bookings(): void
    {
        $client = $this->customer();
        $other = $this->customer();
        [$provider] = $this->provider();
        $this->booking($client, $provider, ['status' => 'completed']);
        $this->booking($other, $provider, ['status' => 'completed']);

        $bookings = $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/auth/me/data-export')
            ->assertOk()
            ->json('data.bookings');

        $this->assertCount(1, $bookings);
    }

    public function test_an_account_is_deleted_after_confirming_the_password(): void
    {
        $client = $this->customer();

        $this->withToken($this->clientToken($client))
            ->deleteJson('/api/client/v1/auth/me', [
                'password' => 'SkillServe#2026',
                'reason' => 'No longer need the app.',
            ])
            ->assertOk();

        // Soft-deleted, so an administrator can still restore it.
        $this->assertSoftDeleted('users', ['id' => $client->id]);
        $this->assertSame(0, $client->tokens()->count());
        $this->assertSame($client->id, (int) User::withTrashed()->find($client->id)->deleted_by);

        // The reason lands in the audit trail administrators restore from.
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'users',
            'description' => 'user_self_deleted',
            'subject_id' => $client->id,
            'causer_id' => $client->id,
        ]);
    }

    public function test_the_wrong_password_does_not_delete_the_account(): void
    {
        $client = $this->customer();

        $this->withToken($this->clientToken($client))
            ->deleteJson('/api/client/v1/auth/me', ['password' => 'not-my-password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'That password is incorrect.');

        $this->assertDatabaseHas('users', ['id' => $client->id, 'deleted_at' => null]);
        $this->assertSame(1, $client->tokens()->count());
    }

    public function test_the_password_is_required(): void
    {
        $client = $this->customer();

        $this->withToken($this->clientToken($client))
            ->deleteJson('/api/client/v1/auth/me', [])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $client->id, 'deleted_at' => null]);
    }

    public function test_an_account_with_an_open_booking_cannot_be_deleted(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $token = $this->clientToken($client);

        foreach (['pending', 'confirmed', 'active', 'disputed'] as $status) {
            $booking = $this->booking($client, $provider, ['status' => $status]);

            Auth::forgetGuards();
            $this->withToken($token)
                ->deleteJson('/api/client/v1/auth/me', ['password' => 'SkillServe#2026'])
                ->assertStatus(422)
                ->assertJsonPath('errors.account.0',
                    'Open bookings must be settled before the account can be deleted.');

            $this->assertDatabaseHas('users', ['id' => $client->id, 'deleted_at' => null]);
            $booking->forceDelete();
        }

        // With everything settled the account can go.
        $this->booking($client, $provider, ['status' => 'completed']);
        Auth::forgetGuards();
        $this->withToken($token)
            ->deleteJson('/api/client/v1/auth/me', ['password' => 'SkillServe#2026'])
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $client->id]);
    }

    public function test_a_provider_with_an_open_job_cannot_delete_their_account_either(): void
    {
        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $this->booking($client, $provider, ['status' => 'active']);

        $this->withToken($providerUser->createToken('provider', ['client:auth'])->plainTextToken)
            ->deleteJson('/api/client/v1/auth/me', ['password' => 'SkillServe#2026'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $providerUser->id, 'deleted_at' => null]);
    }

    public function test_a_deleted_account_can_no_longer_use_its_token(): void
    {
        $client = $this->customer();
        $token = $this->clientToken($client);

        $this->withToken($token)
            ->deleteJson('/api/client/v1/auth/me', ['password' => 'SkillServe#2026'])
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/client/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_the_endpoints_need_a_signed_in_mobile_account(): void
    {
        $this->getJson('/api/client/v1/auth/me/data-export')->assertUnauthorized();
        $this->deleteJson('/api/client/v1/auth/me', ['password' => 'x'])->assertUnauthorized();

        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 'active']);
        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->getJson('/api/client/v1/auth/me/data-export')
            ->assertForbidden();
    }

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'customer.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
            'password' => Hash::make('SkillServe#2026'),
        ]);
    }

    private function clientToken(User $client): string
    {
        return $client->createToken('client-test', ['client:auth'])->plainTextToken;
    }

    /**
     * @return array{0: ProviderProfile, 1: User}
     */
    private function provider(): array
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(8).'@skillserve.test',
            'user_type' => 'provider',
            'status' => 'active',
            'password' => Hash::make('SkillServe#2026'),
        ]);
        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Exported Provider',
            'verification_status' => 'verified',
        ]);

        return [$profile, $user];
    }

    private function booking(User $client, ProviderProfile $provider, array $attributes = []): Booking
    {
        $category = ServiceCategory::create(['name' => 'Data '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Exported service',
            'price' => 100,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
            'is_hidden' => false,
        ]);

        return Booking::create(array_merge([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(12)),
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'PHP',
            'scheduled_date' => now()->subDay(),
        ], $attributes));
    }
}
