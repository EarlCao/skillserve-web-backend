<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientAuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_email_address_is_limited_on_the_public_account_endpoints(): void
    {
        foreach (range(1, 10) as $_) {
            $this->postJson('/api/client/v1/auth/forgot-password', ['email' => 'target@skillserve.test'])
                ->assertStatus(202);
        }

        $this->postJson('/api/client/v1/auth/forgot-password', ['email' => 'Target@SkillServe.test'])
            ->assertStatus(429);

        // Another address from the same network is still served.
        $this->postJson('/api/client/v1/auth/forgot-password', ['email' => 'someone@skillserve.test'])
            ->assertStatus(202);
    }

    public function test_one_network_is_limited_across_addresses(): void
    {
        config(['app.env' => 'testing']);
        putenv('CLIENT_AUTH_RATE_LIMIT=3');

        try {
            foreach (range(1, 3) as $i) {
                $this->postJson('/api/client/v1/auth/resend-otp', ['email' => "user{$i}@skillserve.test"]);
            }

            $this->postJson('/api/client/v1/auth/resend-otp', ['email' => 'user9@skillserve.test'])
                ->assertStatus(429);
        } finally {
            putenv('CLIENT_AUTH_RATE_LIMIT');
        }
    }
}
