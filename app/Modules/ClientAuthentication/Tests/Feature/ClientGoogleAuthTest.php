<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientGoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-web-client-id.apps.googleusercontent.com']);
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

    public function test_first_google_sign_in_creates_a_customer_account(): void
    {
        $this->fakeGoogleTokeninfo($this->claims());

        $response = $this->postJson('/api/client/v1/auth/google', [
            'id_token' => str_repeat('a', 30),
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'email' => 'new.google.user@gmail.com',
            'user_type' => 'customer',
            'google_sub' => 'google-sub-123',
        ]);

        $user = User::query()->where('email', 'new.google.user@gmail.com')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail(), 'Google-verified emails must skip OTP.');
    }

    public function test_same_google_account_signs_in_to_the_same_account_without_duplicates(): void
    {
        $this->fakeGoogleTokeninfo($this->claims());
        $this->postJson('/api/client/v1/auth/google', ['id_token' => str_repeat('a', 30)])->assertOk();
        $this->postJson('/api/client/v1/auth/google', ['id_token' => str_repeat('b', 30)])->assertOk();

        $this->assertSame(
            1,
            User::query()->where('google_sub', 'google-sub-123')->count(),
            'Repeat sign-in must reuse the linked account, not create another.',
        );
    }

    public function test_google_sign_in_is_refused_when_email_already_has_a_customer_account(): void
    {
        User::factory()->create([
            'email' => 'taken@gmail.com',
            'user_type' => 'customer',
        ]);

        $this->fakeGoogleTokeninfo($this->claims([
            'sub' => 'google-sub-other',
            'email' => 'taken@gmail.com',
        ]));

        $response = $this->postJson('/api/client/v1/auth/google', [
            'id_token' => str_repeat('c', 30),
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsStringIgnoringCase(
            'already registered',
            (string) $response->json('message'),
        );
        $this->assertSame(
            null,
            User::query()->where('email', 'taken@gmail.com')->value('google_sub'),
            'Refused sign-in must not link the Google account to the existing account.',
        );
    }

    public function test_google_sign_in_is_refused_when_email_already_has_a_provider_account(): void
    {
        User::factory()->create([
            'email' => 'provider@gmail.com',
            'user_type' => 'provider',
        ]);

        $this->fakeGoogleTokeninfo($this->claims([
            'sub' => 'google-sub-provider',
            'email' => 'provider@gmail.com',
        ]));

        $response = $this->postJson('/api/client/v1/auth/google', [
            'id_token' => str_repeat('d', 30),
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsStringIgnoringCase('provider', (string) $response->json('message'));
    }

    public function test_linked_google_account_still_signs_in_even_after_role_change_or_email_edit(): void
    {
        // Account previously created by Google sign-in.
        $user = User::factory()->create([
            'email' => 'linked@gmail.com',
            'user_type' => 'customer',
            'google_sub' => 'google-sub-linked',
        ]);

        $this->fakeGoogleTokeninfo($this->claims([
            'sub' => 'google-sub-linked',
            'email' => 'newaddress@gmail.com', // Google address was changed.
        ]));

        $response = $this->postJson('/api/client/v1/auth/google', [
            'id_token' => str_repeat('e', 30),
        ]);

        $response->assertOk();
        $this->assertSame($user->id, User::query()->where('google_sub', 'google-sub-linked')->firstOrFail()->id);
    }

    public function test_token_with_wrong_audience_is_rejected(): void
    {
        $this->fakeGoogleTokeninfo($this->claims([
            'aud' => 'some-other-client-id.apps.googleusercontent.com',
        ]));

        $response = $this->postJson('/api/client/v1/auth/google', [
            'id_token' => str_repeat('f', 30),
        ]);

        $response->assertStatus(401);
    }

    public function test_service_validation_requires_a_long_id_token(): void
    {
        $this->fakeGoogleTokeninfo($this->claims());

        $response = $this->postJson('/api/client/v1/auth/google', [
            'id_token' => 'short',
        ]);

        $response->assertStatus(422);
    }
}
