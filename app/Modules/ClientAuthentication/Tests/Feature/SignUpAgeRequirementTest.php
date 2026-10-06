<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Services\ClientSessionService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Helpers\AgeRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * SkillServe is for adults only, and a provider's years of experience are
 * counted from age 16: 2 years at 18, 3 at 19, 4 at 20, and so on.
 */
class SignUpAgeRequirementTest extends TestCase
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

    private function birthdayAtAge(int $years): string
    {
        return now()->subYears($years)->toDateString();
    }

    private function customer(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Santos',
            'email' => 'ana@example.com',
            'birthday' => $this->birthdayAtAge(25),
        ], $overrides);
    }

    private function provider(array $overrides = []): array
    {
        return $this->customer(array_merge([
            'email' => 'pro@example.com',
            'specialization' => 'Plumbing',
        ], $overrides));
    }

    public function test_an_adult_can_sign_up_and_turning_18_today_is_old_enough(): void
    {
        $this->postJson('/api/client/v1/auth/register', $this->customer())->assertStatus(202);
        $this->postJson('/api/client/v1/auth/register', $this->customer([
            'email' => 'eighteen@example.com',
            'birthday' => $this->birthdayAtAge(18),
        ]))->assertStatus(202);
    }

    public function test_someone_under_18_cannot_sign_up_as_a_customer_or_provider(): void
    {
        $minor = now()->subYears(18)->addDay()->toDateString();

        $this->postJson('/api/client/v1/auth/register', $this->customer(['birthday' => $minor]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthday' => 'You must be at least 18 years old to use SkillServe.']);

        $this->postJson('/api/client/v1/auth/register-provider', $this->provider(['birthday' => $minor]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthday']);
    }

    public function test_the_birthday_is_required(): void
    {
        $payload = $this->customer();
        unset($payload['birthday']);

        $this->postJson('/api/client/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthday' => 'Enter your date of birth.']);
    }

    public function test_experience_is_capped_by_age(): void
    {
        $this->assertSame(2, AgeRequirement::maxExperienceYears($this->birthdayAtAge(18)));
        $this->assertSame(3, AgeRequirement::maxExperienceYears($this->birthdayAtAge(19)));
        $this->assertSame(4, AgeRequirement::maxExperienceYears($this->birthdayAtAge(20)));
        $this->assertSame(AgeRequirement::MAX_EXPERIENCE_YEARS, AgeRequirement::maxExperienceYears(null));

        $this->postJson('/api/client/v1/auth/register-provider', $this->provider([
            'birthday' => $this->birthdayAtAge(18),
            'experience_years' => 3,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['experience_years' => 'At your age, years of experience can be at most 2.']);

        $this->postJson('/api/client/v1/auth/register-provider', $this->provider([
            'birthday' => $this->birthdayAtAge(20),
            'experience_years' => 4,
        ]))->assertStatus(202);
    }

    public function test_a_google_sign_up_follows_the_same_rules(): void
    {
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response([
            'aud' => 'test-web-client-id.apps.googleusercontent.com',
            'sub' => 'google-sub-age',
            'email' => 'young.google@gmail.com',
            'email_verified' => 'true',
            'exp' => (string) (time() + 3600),
        ])]);

        $this->postJson('/api/client/v1/auth/google/register', [
            'id_token' => str_repeat('a', 30),
            'first_name' => 'Young',
            'last_name' => 'User',
            'role' => 'provider',
            'specialization' => 'Plumbing',
            'birthday' => $this->birthdayAtAge(19),
            'experience_years' => 4,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['experience_years']);

        $this->postJson('/api/client/v1/auth/google/register', [
            'id_token' => str_repeat('a', 30),
            'first_name' => 'Young',
            'last_name' => 'User',
            'role' => 'customer',
            'birthday' => $this->birthdayAtAge(17),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthday']);
    }

    public function test_a_provider_editing_their_profile_is_capped_by_the_birthday_on_the_account(): void
    {
        $user = User::factory()->create([
            'user_type' => 'provider',
            'status' => 'active',
            'email_verified_at' => now(),
            'birthday' => $this->birthdayAtAge(19),
        ]);
        ProviderProfile::create([
            'user_id' => $user->id,
            'specialization' => 'Plumbing',
            'verification_status' => 'verified',
        ]);
        $token = app(ClientSessionService::class)->issue($user)['token'];

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/profile', ['experience_years' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['experience_years' => 'At your age, years of experience can be at most 3.']);

        $this->withToken($token)
            ->patchJson('/api/client/v1/provider/profile', ['experience_years' => 3])
            ->assertOk();
    }

    public function test_a_national_id_of_someone_under_18_is_refused(): void
    {
        $user = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $this->withToken($user->createToken('client', ['client:auth'])->plainTextToken)
            ->postJson('/api/client/v1/identity-verification', [
                'id_number' => '1234567890123456',
                'full_name' => 'Young User',
                'birthdate' => $this->birthdayAtAge(16),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthdate' => 'You must be at least 18 years old to use SkillServe.']);
    }
}
