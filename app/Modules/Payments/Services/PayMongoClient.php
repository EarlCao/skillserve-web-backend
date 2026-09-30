<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Exceptions\GatewayRequestFailed;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for the PayMongo REST API.
 *
 * Authentication is HTTP Basic with the secret key as the username and an
 * empty password. Creation calls carry an `Idempotency-Key`, which PayMongo
 * honours for 24 hours: replaying a key returns the original response instead
 * of charging again, and reusing one with different parameters is rejected.
 *
 * Nothing here logs a request body or a key. A PayMongo error is re-thrown as
 * {@see GatewayRequestFailed} carrying the provider's own message, so callers
 * never have to parse provider JSON.
 *
 * A **live** key is refused unless `payments.paymongo.allow_live` is on. Under
 * ADR-021 nothing routes to PayMongo, so a live key in the environment is a
 * mistake, and the cost of that mistake is real money moving on a flow that
 * has never been run end to end. Refusing here is the last line before the
 * HTTP call.
 */
class PayMongoClient
{
    /** The prefix PayMongo gives keys that move real money. */
    private const LIVE_PREFIX = 'sk_live_';

    /** Whether a key is present at all. */
    public function configured(): bool
    {
        return $this->secret() !== '';
    }

    /**
     * Whether a request could actually be made: a key is present and it is one
     * this environment is allowed to use.
     *
     * Read by the status command so the posture can be checked without making
     * a call.
     */
    public function usable(): bool
    {
        return $this->configured() && ! $this->liveKeyBlocked();
    }

    /** Whether the configured key moves real money. */
    public function isLiveKey(): bool
    {
        return str_starts_with($this->secret(), self::LIVE_PREFIX);
    }

    /** A live key is present but this environment may not use it. */
    public function liveKeyBlocked(): bool
    {
        return $this->isLiveKey() && ! (bool) config('payments.paymongo.allow_live', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> the `data` object
     */
    public function post(string $path, array $attributes, ?string $idempotencyKey = null): array
    {
        $request = $this->request();

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $this->handle($request->post($path, ['data' => ['attributes' => $attributes]]), 'POST '.$path);
    }

    /** @return array<string, mixed> the `data` object */
    public function get(string $path): array
    {
        return $this->handle($this->request()->get($path), 'GET '.$path);
    }

    private function secret(): string
    {
        return trim((string) config('payments.paymongo.secret_key'));
    }

    private function request(): PendingRequest
    {
        $secret = $this->secret();

        if ($secret === '') {
            throw new GatewayRequestFailed('PayMongo is not configured.');
        }

        if ($this->liveKeyBlocked()) {
            // Loud, because this is a misconfiguration that would otherwise
            // move real money the first time anything reached it. The key
            // itself is never logged.
            Log::critical('A live PayMongo key is configured but live payments are not enabled. Refusing the request.', [
                'allow_live' => false,
            ]);

            // Deliberately vague to the caller: an API consumer has no use for
            // the platform's credential posture.
            throw new GatewayRequestFailed('PayMongo is not available.');
        }

        return Http::baseUrl((string) config('payments.paymongo.base_url'))
            ->withBasicAuth($secret, '')
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('payments.paymongo.timeout', 20))
            // A network blip on the way to a payment provider is common and
            // safe to retry: every call that moves money carries an
            // idempotency key, so a duplicate cannot charge twice.
            ->retry(2, 250, throw: false);
    }

    /**
     * @param  Response  $response
     * @return array<string, mixed>
     */
    private function handle($response, string $context): array
    {
        if ($response->successful()) {
            return $response->json('data', []);
        }

        $errors = $response->json('errors', []);
        $message = $errors[0]['detail'] ?? 'The payment provider rejected the request.';

        // Status and the provider's message only — never the payload, which
        // carries customer and payment details.
        Log::warning('PayMongo request failed.', [
            'context' => $context,
            'status' => $response->status(),
            'code' => $errors[0]['code'] ?? null,
        ]);

        throw new GatewayRequestFailed($message);
    }
}
