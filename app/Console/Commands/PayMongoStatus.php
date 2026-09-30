<?php

namespace App\Console\Commands;

use App\Modules\Bookings\Enums\PaymentMethod;
use App\Modules\Payments\Services\PayMongoClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reports this environment's PayMongo posture, and can tell whether a key is
 * still accepted by PayMongo.
 *
 * Written for the key rotation in `PENDING_FIXES.md` (**C5**): an `sk_live_`
 * key was exposed and had to be regenerated, and "the old key returns 401" is
 * how that is confirmed. Rotation happens in PayMongo's dashboard, which no
 * command can do — this checks the result.
 *
 * Nothing here prints or logs a key. A key typed at the prompt is held for the
 * one request and never written anywhere.
 */
class PayMongoStatus extends Command
{
    protected $signature = 'paymongo:status
                            {--probe : Ask PayMongo whether the configured key is still accepted}
                            {--probe-key : Prompt for a key (hidden) and probe that instead, e.g. a rotated-out one}';

    protected $description = 'Report the PayMongo configuration, and optionally check whether a key is still accepted';

    public function handle(PayMongoClient $client): int
    {
        $this->components->info('PayMongo configuration');

        $mode = match (true) {
            ! $client->configured() => 'not set',
            $client->isLiveKey() => 'LIVE (sk_live_)',
            default => 'test (sk_test_)',
        };

        $this->components->twoColumnDetail('Secret key', $mode);
        $this->components->twoColumnDetail('Live payments allowed', config('payments.paymongo.allow_live') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Webhook secret', trim((string) config('payments.paymongo.webhook_secret')) === '' ? 'not set' : 'set');

        foreach (PaymentMethod::cases() as $method) {
            $this->components->twoColumnDetail(
                'Gateway for '.$method->value,
                (string) config("payments.gateways.{$method->value}", 'manual'),
            );
        }

        $this->newLine();

        // The healthy state, and why. Both methods settle directly between the
        // customer and the provider (ADR-021), so PayMongo should be holding
        // nothing and reachable by nothing.
        if (! $client->configured()) {
            $this->components->info('No key is set, which is the expected state: no booking is payable online, so nothing needs one.');
        } elseif ($client->liveKeyBlocked()) {
            $this->components->error('A LIVE key is set but live payments are not enabled, so every request is refused. Remove PAYMONGO_SECRET_KEY unless you meant to enable live payments.');
        } elseif ($client->isLiveKey()) {
            $this->components->warn('A LIVE key is set AND live payments are enabled. Real money can move. This is not needed for bookings.');
        } else {
            $this->components->warn('A test key is set. Harmless, but nothing uses it while both methods route to the manual gateway.');
        }

        if ($this->option('probe-key')) {
            $key = trim((string) $this->secret('Key to probe (not echoed, not stored)'));

            if ($key === '') {
                $this->components->error('No key entered.');

                return self::FAILURE;
            }

            return $this->probe($key);
        }

        if ($this->option('probe')) {
            if (! $client->configured()) {
                $this->components->error('Nothing to probe: no key is configured.');

                return self::FAILURE;
            }

            return $this->probe(trim((string) config('payments.paymongo.secret_key')));
        }

        return self::SUCCESS;
    }

    /**
     * Asks PayMongo to read a payment intent that cannot exist.
     *
     * A rejected key answers **401** whatever it is asked for; an accepted one
     * gets as far as "no such intent". So the status alone says whether the key
     * still works, without creating anything or moving money.
     */
    private function probe(string $key): int
    {
        $this->newLine();
        $this->components->info('Probing PayMongo…');

        try {
            $response = Http::baseUrl((string) config('payments.paymongo.base_url'))
                ->withBasicAuth($key, '')
                ->acceptJson()
                ->timeout((int) config('payments.paymongo.timeout', 20))
                ->get('/payment_intents/pi_thiskeycheckdoesnotexist');
        } catch (ConnectionException $e) {
            $this->components->error('Could not reach PayMongo: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->status() === 401) {
            $this->components->info('PayMongo answered 401: this key is REJECTED. A rotated-out key should look like this.');

            return self::SUCCESS;
        }

        $this->components->warn('PayMongo answered '.$response->status().': this key is still ACCEPTED. If it is the exposed one, it has not been rotated yet.');

        return self::FAILURE;
    }
}
