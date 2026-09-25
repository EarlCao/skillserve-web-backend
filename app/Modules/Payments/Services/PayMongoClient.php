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
 */
class PayMongoClient
{
    public function configured(): bool
    {
        return (string) config('payments.paymongo.secret_key') !== '';
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

    private function request(): PendingRequest
    {
        $secret = (string) config('payments.paymongo.secret_key');

        if ($secret === '') {
            throw new GatewayRequestFailed('PayMongo is not configured.');
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
