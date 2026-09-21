<?php

namespace App\Modules\ClientCommunication\Tests\Feature;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderSupportTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provider_raises_follows_and_replies_to_a_support_ticket(): void
    {
        $provider = $this->provider();
        $token = $provider->createToken('provider', ['client:auth'])->plainTextToken;

        $ticket = $this->withToken($token)
            ->postJson('/api/client/v1/support/tickets', [
                'subject' => 'My service is stuck in review',
                'description' => 'It has been pending for a week.',
                'category' => 'account',
            ])
            ->assertCreated()
            ->json('data');

        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/client/v1/support/tickets')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $ticket['id']);

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson("/api/client/v1/support/tickets/{$ticket['id']}/replies", ['body' => 'Any update?'])
            ->assertOk();
    }

    public function test_a_provider_cannot_see_a_customers_ticket(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $ticket = $this->withToken($customer->createToken('client', ['client:auth'])->plainTextToken)
            ->postJson('/api/client/v1/support/tickets', [
                'subject' => 'Private question',
                'description' => 'Only mine.',
            ])
            ->assertCreated()
            ->json('data');

        Auth::forgetGuards();
        $providerToken = $this->provider()->createToken('provider', ['client:auth'])->plainTextToken;

        $this->withToken($providerToken)
            ->getJson("/api/client/v1/support/tickets/{$ticket['id']}")
            ->assertNotFound();

        Auth::forgetGuards();
        $this->withToken($providerToken)
            ->getJson('/api/client/v1/support/tickets')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_administrators_still_cannot_use_the_mobile_support_endpoints(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 'active']);

        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->getJson('/api/client/v1/support/tickets')
            ->assertForbidden();
    }

    private function provider(): User
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(8).'@skillserve.test',
            'user_type' => 'provider',
            'status' => 'active',
        ]);
        ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Support Provider',
            'verification_status' => 'verified',
        ]);

        return $user;
    }
}
