<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientGoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-web-client-id.apps.googleusercontent.com']);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Fake Google's tokeninfo endpoint to return the given claims for any
     * token, recording the requests so tests can assert on them.
     *
     * @param  array<string, mixed>  $claims
     */
    private function fakeGoogleTokeninfo(array $claims): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response($claims),
        ]);
    }

    private function claims(array $overrides = []): array
    {
        return array_merge([
            'aud' => 'test-web-client-id.apps.googleusercontent.com',
            'sub' => 'google-sub-123',
            'email' => 'new.google.user@gmail.com',
            'email_verified' => 'true',
            'exp' => (string) (time() + 3600),
            'name' => 'New Google User',
            'given_name' => 'New',
            'family_name' => 'User',
        ], $overrides);
    }

    private function signInWithGoogle(string $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): TestResponse
    {
        return $this->postJson('/api/client/v1/auth/google', ['id_token' => $token]);
    }

    public function test_an_unknown_google_account_is_not_created_until_the_form_is_submitted(): void
    {
        $this->fakeGoogleTokeninfo($this->claims());

        $this->signInWithGoogle()
            ->assertOk()
            ->assertJsonPath('data.registration_required', true)
            ->assertJsonPath('data.google.email', 'new.google.user@gmail.com')
            ->assertJsonPath('data.google.first_name', 'New')
            ->assertJsonPath('data.google.last_name', 'User');

        // Backing out of the form must leave nothing behind, so the same
        // Google account can start over as either role.
        $this->assertDatabaseMissing('users', ['email' => 'new.google.user@gmail.com']);
        $this->assertDatabaseCount('pending_registrations', 0);

        $this->signInWithGoogle()->assertOk()->assertJsonPath('data.registration_required', true);
        $this->assertDatabaseMissing('users', ['email' => 'new.google.user@gmail.com']);
    }

    public function test_completing_the_form_creates_a_verified_customer_and_signs_in(): void
    {
        $this->fakeGoogleTokeninfo($this->claims());

        $this->postJson('/api/client/v1/auth/google/register', [
            'id_token' => str_repeat('a', 30),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'role' => 'customer',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'new.google.user@gmail.com')
            ->assertJsonPath('data.user.first_name', 'Juan')
            ->assertJsonPath('data.user.user_type', 'customer')
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonStructure(['data' => ['token', 'refresh_token']]);

        $this->assertDatabaseHas('users', [
            'email' => 'new.google.user@gmail.com',
            'role_id' => 4,
            'google_sub' => 'google-sub-123',
        ]);

        // The same Google account now signs straight in.
        $this->signInWithGoogle()
            ->assertOk()
            ->assertJsonPath('data.registration_required', false)
            ->assertJsonPath('data.user.email', 'new.google.user@gmail.com');

        $this->assertSame(1, User::query()->where('google_sub', 'google-sub-123')->count());
    }

    public function test_completing_the_form_as_a_provider_creates_the_pending_profile(): void
    {
        $this->fakeGoogleTokeninfo($this->claims());

        $this->postJson('/api/client/v1/auth/google/register', [
            'id_token' => str_repeat('a', 30),
            'first_name' => 'Pro',
            'last_name' => 'Vider',
            'role' => 'provider',
            'business_name' => 'Pro Repairs',
            'specialization' => 'Plumbing',
            'experience_years' => 5,
            'bio' => 'Licensed plumber.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.user_type', 'provider')
            ->assertJsonPath('data.user.provider.specialization', 'Plumbing');

        $user = User::query()->where('email', 'new.google.user@gmail.com')->firstOrFail();
        $profile = ProviderProfile::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('Pro Repairs', $profile->business_name);
        $this->assertSame(5, $profile->experience_years);
        $this->assertSame('pending', $profile->verification_status);
    }

    public function test_provider_signup_requires_a_specialization(): void
    {
        $this->fakeGoogleTokeninfo($this->claims());

        $this->postJson('/api/client/v1/auth/google/register', [
            'id_token' => str_repeat('a', 30),
            'first_name' => 'Pro',
            'last_name' => 'Vider',
            'role' => 'provider',
        ])->assertJsonValidationErrors(['specialization']);

        $this->assertDatabaseMissing('users', ['email' => 'new.google.user@gmail.com']);
    }

    public function test_google_signs_in_to_an_existing_email_password_account_and_links_it(): void
    {
        // The exact report: signing up with email + password, then tapping
        // "Continue with Google" used to answer "already registered".
        $user = User::factory()->create([
            'email' => 'taken@gmail.com',
            'password' => Hash::make('password123'),
            'user_type' => 'customer',
            'email_verified_at' => now(),
        ]);

        $this->fakeGoogleTokeninfo($this->claims([
            'sub' => 'google-sub-other',
            'email' => 'taken@gmail.com',
        ]));

        $this->signInWithGoogle()
            ->assertOk()
            ->assertJsonPath('data.registration_required', false)
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertSame('google-sub-other', $user->fresh()->google_sub);

        // The password still works — linking Google does not replace it.
        $this->postJson('/api/client/v1/auth/login', [
            'email' => 'taken@gmail.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_google_signs_in_a_provider_account_that_owns_the_email(): void
    {
        $user = User::factory()->create([
            'email' => 'provider@gmail.com',
            'user_type' => 'provider',
            'email_verified_at' => now(),
        ]);
        ProviderProfile::create([
            'user_id' => $user->id,
            'specialization' => 'Home Repair',
            'verification_status' => 'pending',
        ]);

        $this->fakeGoogleTokeninfo($this->claims([
            'sub' => 'google-sub-provider',
            'email' => 'provider@gmail.com',
        ]));

        $this->signInWithGoogle()
            ->assertOk()
            ->assertJsonPath('data.registration_required', false)
            ->assertJsonPath('data.user.user_type', 'provider');
    }

    public function test_google_verifies_an_account_that_never_confirmed_its_email(): void
    {
        $user = User::factory()->create([
            'email' => 'unverified@gmail.com',
            'user_type' => 'customer',
            'email_verified_at' => null,
        ]);

        $this->fakeGoogleTokeninfo($this->claims(['email' => 'unverified@gmail.com']));

        $this->signInWithGoogle()->assertOk()->assertJsonPath('data.user.email_verified', true);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_google_signin_is_refused_for_an_administrator_email(): void
    {
        Notification::fake();
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin@gmail.com', 'email_verified_at' => now()]);
        $admin->assignRole('admin');
        $admin->forceFill(['role_id' => 2])->save();

        $this->fakeGoogleTokeninfo($this->claims(['email' => 'admin@gmail.com']));

        $this->signInWithGoogle()->assertStatus(403);
    }

    public function test_google_signin_is_refused_for_a_suspended_account(): void
    {
        User::factory()->create([
            'email' => 'suspended@gmail.com',
            'user_type' => 'customer',
            'status' => 'suspended',
            'email_verified_at' => now(),
        ]);

        $this->fakeGoogleTokeninfo($this->claims(['email' => 'suspended@gmail.com']));

        $this->signInWithGoogle()->assertStatus(403);
    }

    public function test_linked_google_account_still_signs_in_even_after_role_change_or_email_edit(): void
    {
        // Account previously created by Google sign-in.
        $user = User::factory()->create([
            'email' => 'linked@gmail.com',
            'user_type' => 'customer',
            'google_sub' => 'google-sub-linked',
            'email_verified_at' => now(),
        ]);

        $this->fakeGoogleTokeninfo($this->claims([
            'sub' => 'google-sub-linked',
            'email' => 'newaddress@gmail.com', // Google address was changed.
        ]));

        $this->signInWithGoogle()->assertOk()->assertJsonPath('data.registration_required', false);

        $this->assertSame($user->id, User::query()->where('google_sub', 'google-sub-linked')->firstOrFail()->id);
    }

    public function test_google_signup_supersedes_an_unverified_email_signup_for_the_same_address(): void
    {
        Notification::fake();

        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Alex',
            'last_name' => 'Customer',
            'email' => 'new.google.user@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(202);

        $this->fakeGoogleTokeninfo($this->claims());

        $this->postJson('/api/client/v1/auth/google/register', [
            'id_token' => str_repeat('a', 30),
            'first_name' => 'New',
            'last_name' => 'User',
            'role' => 'customer',
        ])->assertCreated();

        $this->assertDatabaseMissing('pending_registrations', ['email' => 'new.google.user@gmail.com']);
        $this->assertSame(1, User::query()->where('email', 'new.google.user@gmail.com')->count());
    }

    public function test_token_with_wrong_audience_is_rejected(): void
    {
        $this->fakeGoogleTokeninfo($this->claims([
            'aud' => 'some-other-client-id.apps.googleusercontent.com',
        ]));

        $this->signInWithGoogle()->assertStatus(401);

        $this->postJson('/api/client/v1/auth/google/register', [
            'id_token' => str_repeat('a', 30),
            'first_name' => 'New',
            'last_name' => 'User',
            'role' => 'customer',
        ])->assertStatus(401);
    }

    public function test_an_expired_or_unverified_google_token_is_rejected(): void
    {
        $this->fakeGoogleTokeninfo($this->claims(['exp' => (string) (time() - 10)]));
        $this->signInWithGoogle()->assertStatus(401);

        $this->fakeGoogleTokeninfo($this->claims(['email_verified' => 'false']));
        $this->signInWithGoogle()->assertStatus(401);
    }

    public function test_service_validation_requires_a_long_id_token(): void
    {
        $this->fakeGoogleTokeninfo($this->claims());

        $this->postJson('/api/client/v1/auth/google', ['id_token' => 'short'])->assertStatus(422);
    }
}
