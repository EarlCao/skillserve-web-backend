<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FavoriteProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_saves_lists_and_removes_favorites_newest_first(): void
    {
        $client = $this->customer();
        $first = $this->provider('First Co.');
        $second = $this->provider('Second Co.');
        $token = $this->token($client);

        $this->withToken($token)->putJson("/api/client/v1/favorites/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id);
        $this->travel(1)->seconds();
        $this->withToken($token)->putJson("/api/client/v1/favorites/{$second->id}")->assertOk();

        $this->withToken($token)->getJson('/api/client/v1/favorites')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.business_name', 'First Co.');

        $this->withToken($token)->deleteJson("/api/client/v1/favorites/{$second->id}")->assertOk();

        $this->withToken($token)->getJson('/api/client/v1/favorites')
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $first->id);
    }

    public function test_saving_and_removing_are_idempotent(): void
    {
        $client = $this->customer();
        $provider = $this->provider();
        $token = $this->token($client);

        $this->withToken($token)->putJson("/api/client/v1/favorites/{$provider->id}")->assertOk();
        $this->withToken($token)->putJson("/api/client/v1/favorites/{$provider->id}")->assertOk();
        $this->assertDatabaseCount('favorite_providers', 1);

        $this->withToken($token)->deleteJson("/api/client/v1/favorites/{$provider->id}")->assertOk();
        $this->withToken($token)->deleteJson("/api/client/v1/favorites/{$provider->id}")->assertOk();
        $this->assertDatabaseCount('favorite_providers', 0);
    }

    public function test_a_hidden_provider_cannot_be_saved_and_drops_out_of_the_list(): void
    {
        $client = $this->customer();
        $token = $this->token($client);
        $unverified = $this->provider('Pending Co.', ['verification_status' => 'pending']);

        $this->withToken($token)->putJson("/api/client/v1/favorites/{$unverified->id}")->assertNotFound();

        $provider = $this->provider();
        $this->withToken($token)->putJson("/api/client/v1/favorites/{$provider->id}")->assertOk();
        $provider->update(['suspended_at' => now()]);

        // The saved row stays, so the provider reappears if reinstated.
        $this->withToken($token)->getJson('/api/client/v1/favorites')->assertJsonPath('meta.pagination.total', 0);
        $this->assertDatabaseCount('favorite_providers', 1);
    }

    public function test_each_customer_only_sees_their_own_favorites(): void
    {
        $provider = $this->provider();
        $this->withToken($this->token($this->customer()))->putJson("/api/client/v1/favorites/{$provider->id}")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($this->customer()))->getJson('/api/client/v1/favorites')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_only_customers_have_favorites(): void
    {
        $provider = $this->provider();
        $providerToken = $this->token(User::query()->findOrFail($provider->user_id));

        $this->withToken($providerToken)->getJson('/api/client/v1/favorites')->assertForbidden();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/client/v1/favorites/{$provider->id}")->assertUnauthorized();
    }

    public function test_favorites_are_part_of_the_account_data_export(): void
    {
        $client = $this->customer();
        $provider = $this->provider('Exported Co.');
        $client->favoriteProviders()->attach($provider->id);

        $this->withToken($this->token($client))->getJson('/api/client/v1/auth/me/data-export')
            ->assertOk()
            ->assertJsonPath('data.favorite_providers.0.business_name', 'Exported Co.');
    }

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'client.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    private function provider(string $name = 'Verified Provider', array $attributes = []): ProviderProfile
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(8).'@skillserve.test',
            'user_type' => 'provider',
            'status' => 'active',
        ]);

        return ProviderProfile::create(array_merge([
            'user_id' => $user->id,
            'business_name' => $name,
            'verification_status' => 'verified',
        ], $attributes));
    }

    private function token(User $user): string
    {
        return $user->createToken('test', ['client:auth'])->plainTextToken;
    }
}
