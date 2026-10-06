<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mobile sign-up order: details -> emailed code -> password -> account.
 */
class SignUpPasswordStepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** Step 1, without a password; returns the start response. */
    private function start(string $email = 'steps@example.com', string $endpoint = '/api/client/v1/auth/register', array $extra = []): TestResponse
    {
        $response = $this->postJson($endpoint, [
            'first_name' => 'Step',
            'birthday' => '1995-04-02',
            'last_name' => 'User',
            'email' => $email,
            ...$extra,
        ]);

        PendingRegistration::query()->where('email', $email)->first()
            ?->forceFill(['email_otp_hash' => Hash::make('123456')])->save();

        return $response;
    }

    private function verify(string $email = 'steps@example.com', string $code = '123456'): TestResponse
    {
        return $this->postJson('/api/client/v1/auth/verify-otp', ['email' => $email, 'code' => $code]);
    }

    private function complete(string $token, string $email = 'steps@example.com', string $password = 'chosenpass123', ?string $confirmation = null): TestResponse
    {
        return $this->postJson('/api/client/v1/auth/complete-registration', [
            'email' => $email,
            'registration_token' => $token,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    public function test_the_account_is_created_only_after_the_code_and_then_the_password(): void
    {
        $token = $this->start()
            ->assertStatus(202)
            ->assertJsonPath('data.verification_required', true)
            ->assertJsonPath('data.password_required', true)
            ->json('data.registration_token');

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseMissing('users', ['email' => 'steps@example.com']);

        // The token is stored only as a hash.
        $this->assertNotSame($token, PendingRegistration::query()->firstOrFail()->registration_token_hash);

        $this->verify()
            ->assertOk()
            ->assertJsonPath('data.verification_required', false)
            ->assertJsonPath('data.password_required', true)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.registration_token');
        $this->assertDatabaseMissing('users', ['email' => 'steps@example.com']);

        // A repeated submission of the code screen is harmless.
        $this->verify()->assertOk()->assertJsonPath('data.password_required', true);

        $this->complete($token)
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'steps@example.com')
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonStructure(['data' => ['token', 'refresh_token']]);

        $this->assertDatabaseCount('pending_registrations', 0);
        $this->postJson('/api/client/v1/auth/login', [
            'email' => 'steps@example.com',
            'password' => 'chosenpass123',
        ])->assertOk();
    }

    public function test_the_password_cannot_be_set_before_the_code_is_confirmed(): void
    {
        $token = $this->start()->json('data.registration_token');

        $this->complete($token)->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'steps@example.com']);
    }

    public function test_the_password_step_needs_the_token_from_the_device_that_started(): void
    {
        $this->start();
        $this->verify()->assertOk();

        $this->complete(str_repeat('x', 64))->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'steps@example.com']);
    }

    public function test_the_password_must_be_confirmed_and_long_enough(): void
    {
        $token = $this->start()->json('data.registration_token');
        $this->verify()->assertOk();

        $this->complete($token, password: 'chosenpass123', confirmation: 'different123')
            ->assertJsonValidationErrors(['password']);
        $this->complete($token, password: 'short')
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'steps@example.com']);
    }

    public function test_completing_twice_does_not_create_a_second_account(): void
    {
        $token = $this->start()->json('data.registration_token');
        $this->verify()->assertOk();

        $this->complete($token)->assertCreated();
        $this->complete($token)->assertStatus(422);

        $this->assertSame(1, User::query()->where('email', 'steps@example.com')->count());
    }

    public function test_a_provider_signup_takes_the_same_steps(): void
    {
        $token = $this->start('pro.steps@example.com', '/api/client/v1/auth/register-provider', [
            'specialization' => 'Electrical',
            'experience_years' => 4,
        ])->assertStatus(202)->json('data.registration_token');

        $this->verify('pro.steps@example.com')->assertOk();

        $this->complete($token, 'pro.steps@example.com')
            ->assertCreated()
            ->assertJsonPath('data.user.user_type', 'provider');

        $user = User::query()->where('email', 'pro.steps@example.com')->firstOrFail();
        $this->assertSame('Electrical', ProviderProfile::query()->where('user_id', $user->id)->value('specialization'));
    }

    public function test_resend_after_the_code_was_confirmed_reports_it_as_verified(): void
    {
        $this->start();
        $this->verify()->assertOk();
        $this->travel(61)->seconds();

        $this->postJson('/api/client/v1/auth/resend-otp', ['email' => 'steps@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'Email is already verified.');
    }

    public function test_cancelling_needs_the_registration_token(): void
    {
        $token = $this->start()->json('data.registration_token');

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'steps@example.com',
            'registration_token' => str_repeat('x', 64),
        ])->assertOk();
        $this->assertDatabaseHas('pending_registrations', ['email' => 'steps@example.com']);

        $this->postJson('/api/client/v1/auth/cancel-registration', [
            'email' => 'steps@example.com',
            'registration_token' => $token,
        ])->assertOk();
        $this->assertDatabaseMissing('pending_registrations', ['email' => 'steps@example.com']);

        // The address is free again straight away.
        $this->start()->assertStatus(202);
    }
}
