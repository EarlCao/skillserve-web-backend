<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Sign-up refuses an email whose domain cannot receive mail, so the code is
 * never sent to an address that would bounce. Bounces get a sending account
 * suspended, which would stop every later code.
 */
class SignUpEmailDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['client-auth.check_email_domain' => true]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_an_email_on_a_domain_that_cannot_receive_mail_is_refused_before_any_code_is_sent(): void
    {
        foreach ([
            ['/api/client/v1/auth/register', []],
            ['/api/client/v1/auth/register-provider', ['specialization' => 'Plumbing']],
        ] as [$endpoint, $extra]) {
            // The .invalid top-level domain never exists.
            $this->postJson($endpoint, ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana@skillserve-typo.invalid', 'birthday' => '1995-04-02', ...$extra])
                ->assertStatus(422)
                ->assertJsonPath('errors.email.0', 'Enter an email address that can receive mail, and check the spelling.');
        }

        $this->assertDatabaseCount('pending_registrations', 0);
        Notification::assertNothingSent();
    }
}
