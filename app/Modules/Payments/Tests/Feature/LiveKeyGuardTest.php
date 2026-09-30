<?php

namespace App\Modules\Payments\Tests\Feature;

use App\Modules\Payments\Exceptions\GatewayRequestFailed;
use App\Modules\Payments\Services\PayMongoClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A live PayMongo key must not be usable by accident.
 *
 * Context (`PENDING_FIXES.md` **C5**): an `sk_live_` key was exposed and has to
 * be rotated. Rotation happens in PayMongo's dashboard, which no code can do —
 * what code can do is make sure this application never spends real money
 * through a key it was not explicitly told it may use. Under ADR-021 nothing
 * routes to PayMongo at all, so a live key present in the environment is a
 * misconfiguration by definition.
 */
class LiveKeyGuardTest extends TestCase
{
    private function client(?string $key, bool $allowLive = false): PayMongoClient
    {
        config([
            'payments.paymongo.secret_key' => $key,
            'payments.paymongo.allow_live' => $allowLive,
            'payments.paymongo.base_url' => 'https://api.paymongo.test/v1',
        ]);

        return app(PayMongoClient::class);
    }

    public function test_a_live_key_is_refused_before_any_http_call(): void
    {
        Http::fake();
        Log::spy();

        $client = $this->client('sk_live_exposed');

        $this->assertTrue($client->configured());
        $this->assertTrue($client->isLiveKey());
        $this->assertTrue($client->liveKeyBlocked());
        $this->assertFalse($client->usable());

        try {
            $client->post('/payment_intents', ['amount' => 20000], 'key-1');
            $this->fail('A blocked live key should not have reached PayMongo.');
        } catch (GatewayRequestFailed $e) {
            // Vague on purpose: an API consumer has no use for the platform's
            // credential posture.
            $this->assertSame('PayMongo is not available.', $e->getMessage());
            $this->assertSame(502, $e->status);
        }

        // The point of the guard: nothing left the process.
        Http::assertNothingSent();

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_the_refusal_never_logs_the_key(): void
    {
        Http::fake();

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message->message.' '.json_encode($message->context);
        });

        try {
            $this->client('sk_live_supersecretvalue')->get('/payment_intents/pi_1');
        } catch (GatewayRequestFailed) {
            // Expected.
        }

        $this->assertNotEmpty($captured);
        foreach ($captured as $line) {
            $this->assertStringNotContainsString('supersecretvalue', $line);
            $this->assertStringNotContainsString('sk_live_', $line);
        }
    }

    public function test_a_live_key_works_only_when_live_payments_are_explicitly_enabled(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'pi_1']])]);

        $client = $this->client('sk_live_deliberate', allowLive: true);

        $this->assertFalse($client->liveKeyBlocked());
        $this->assertTrue($client->usable());
        $this->assertSame(['id' => 'pi_1'], $client->get('/payment_intents/pi_1'));
    }

    public function test_a_test_key_is_never_blocked(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'pi_2']])]);

        $client = $this->client('sk_test_fake');

        $this->assertFalse($client->isLiveKey());
        $this->assertFalse($client->liveKeyBlocked());
        $this->assertTrue($client->usable());
        $this->assertSame(['id' => 'pi_2'], $client->get('/payment_intents/pi_2'));
    }

    public function test_an_absent_key_is_reported_as_unusable_rather_than_blocked(): void
    {
        $client = $this->client(null);

        $this->assertFalse($client->configured());
        $this->assertFalse($client->usable());
        // Not "blocked": there is nothing to block. The distinction is what
        // lets the status command call an empty environment healthy.
        $this->assertFalse($client->liveKeyBlocked());
    }

    public function test_live_payments_are_off_by_default(): void
    {
        // A fresh environment must never be one flipped variable away from
        // moving real money.
        $this->assertFalse((bool) config('payments.paymongo.allow_live'));
    }

    public function test_the_status_command_reports_a_blocked_live_key(): void
    {
        $this->client('sk_live_exposed');

        $this->artisan('paymongo:status')
            ->expectsOutputToContain('LIVE (sk_live_)')
            ->expectsOutputToContain('every request is refused')
            ->assertExitCode(0);
    }

    public function test_the_status_command_calls_an_empty_environment_expected(): void
    {
        $this->client(null);

        $this->artisan('paymongo:status')
            ->expectsOutputToContain('not set')
            ->expectsOutputToContain('expected state')
            ->assertExitCode(0);
    }

    public function test_probing_reports_a_rotated_out_key_as_rejected(): void
    {
        // What PayMongo answers to any request made with a key it no longer
        // recognises, which is how C5's rotation is confirmed.
        Http::fake(['*' => Http::response(['errors' => [['detail' => 'Unauthorized.']]], 401)]);

        $this->client('sk_live_old', allowLive: true);

        $this->artisan('paymongo:status --probe')
            ->expectsOutputToContain('REJECTED')
            ->assertExitCode(0);
    }

    public function test_probing_reports_a_key_that_still_works_as_a_failure(): void
    {
        // 404 means the key authenticated and PayMongo simply had no such
        // intent — so the key is alive. For an exposed key that is a failure.
        Http::fake(['*' => Http::response(['errors' => [['detail' => 'No such payment intent.']]], 404)]);

        $this->client('sk_test_alive');

        $this->artisan('paymongo:status --probe')
            ->expectsOutputToContain('still ACCEPTED')
            ->assertExitCode(1);
    }

    public function test_probing_without_a_key_fails_rather_than_pretending(): void
    {
        Http::fake();

        $this->client(null);

        $this->artisan('paymongo:status --probe')
            ->expectsOutputToContain('no key is configured')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }
}
