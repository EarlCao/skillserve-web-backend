<?php

namespace App\Shared\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Brevo whether the codes are really going out, for GET /api/health.
 *
 * Brevo answers 201 to every send it accepts, even from an account it has
 * stopped delivering for, so the app cannot see the failures that matter: a
 * key or server IP Brevo no longer accepts, a used-up sending allowance, or a
 * suspended account. This reads the account (key, IP, credits) and the last
 * two days of transactional statistics (accepted but none delivered).
 *
 * The answer is cached for a few minutes so the health page never hammers
 * Brevo, and it carries fixed wording only — Brevo's own messages, which can
 * name the server's IP address, go to the log.
 */
class BrevoAccountCheck
{
    public const CACHE_KEY = 'health:brevo-account';

    private const CACHE_SECONDS = 300;

    private const BASE = 'https://api.brevo.com/v3/';

    /** Short, so a slow Brevo cannot hold up the health page. */
    private const TIMEOUT_SECONDS = 5;

    /** Accepted emails with none delivered, from this many on, mean a stopped account. */
    private const UNDELIVERED_THRESHOLD = 3;

    /** @return array{status: string, error?: string} */
    public function check(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->ask());
        } catch (Throwable) {
            // The cache lives in the database; with it down the health page
            // still answers (and reports the database), just uncached.
            return $this->ask();
        }
    }

    /** @return array{status: string, error?: string} */
    private function ask(): array
    {
        try {
            $account = $this->get('account');

            if ($account->status() === 401) {
                $this->log('Brevo refused the API key or this server\'s IP address.', $account->json('message'));

                return $this->down('Brevo refused the API key or this server\'s IP address (Brevo → Security → Authorized IPs).');
            }
            if (! $account->successful()) {
                $this->log("Brevo account check answered HTTP {$account->status()}.", $account->json('message'));

                return $this->down("Brevo answered HTTP {$account->status()} to the account check.");
            }

            foreach ((array) $account->json('plan', []) as $plan) {
                if (($plan['creditsType'] ?? null) === 'sendLimit' && (int) ($plan['credits'] ?? 1) <= 0) {
                    return $this->down('The Brevo sending allowance is used up; email resumes when it renews.');
                }
            }

            $report = $this->get('smtp/statistics/aggregatedReport', ['days' => 2]);
            $requests = (int) $report->json('requests', 0);

            if ($report->successful() && $requests >= self::UNDELIVERED_THRESHOLD && (int) $report->json('delivered', 0) === 0) {
                return $this->down("Brevo accepted {$requests} emails in the last two days and delivered none. Check Brevo → Transactional → Logs; the account may be suspended.");
            }

            return ['status' => 'up'];
        } catch (Throwable $e) {
            $this->log('Brevo could not be reached for the account check.', $e->getMessage());

            return $this->down('Brevo could not be reached.');
        }
    }

    /** @param  array<string, mixed>  $query */
    private function get(string $path, array $query = []): Response
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['api-key' => (string) config('services.brevo.api_key'), 'accept' => 'application/json'])
            ->get(self::BASE.$path, $query);
    }

    /** @return array{status: string, error: string} */
    private function down(string $error): array
    {
        return ['status' => 'down', 'error' => $error];
    }

    private function log(string $message, mixed $detail): void
    {
        Log::channel('stderr')->warning($message, ['brevo' => $detail]);
    }
}
