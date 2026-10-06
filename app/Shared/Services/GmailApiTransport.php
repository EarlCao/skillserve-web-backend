<?php

namespace App\Shared\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends email as a Gmail account through the Gmail API
 * (users.messages.send) over HTTPS — free, no email company to approve the
 * account, and the mail really comes from Gmail. Render's free tier blocks
 * SMTP, so Gmail's SMTP server is not an option; its API is.
 *
 * Authorised once with OAuth: a refresh token for the sending account, with
 * the gmail.send scope, is exchanged for a short-lived access token, which is
 * cached until shortly before it expires. Gmail sends from the authorised
 * account, so MAIL_FROM_ADDRESS should be that same address.
 *
 * Enable with MAIL_MAILER=gmail-api, GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET and
 * GMAIL_REFRESH_TOKEN (DEPLOYMENT.md → "Email codes").
 */
class GmailApiTransport implements TransportInterface
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SEND_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private const TOKEN_CACHE_KEY = 'gmail-api:access-token';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        private readonly int $timeoutSeconds = 15,
    ) {
        if (trim($this->clientId) === '' || trim($this->clientSecret) === '' || trim($this->refreshToken) === '') {
            throw new TransportException('GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET and GMAIL_REFRESH_TOKEN must be set for the gmail-api mailer.');
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if (! $message instanceof Message) {
            throw new TransportException('Gmail API transport only supports MIME messages.');
        }

        $envelope ??= Envelope::create($message);
        // The whole MIME message, base64url-encoded, is what Gmail sends.
        $raw = rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '=');

        $response = $this->post($raw);

        // A cached token Google no longer accepts: fetch a fresh one, once.
        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->post($raw);
        }

        if (! $response->successful()) {
            throw new TransportException(sprintf(
                'Gmail API returned HTTP %d: %s',
                $response->status(),
                $response->json('error.message') ?? substr((string) $response->body(), 0, 500),
            ));
        }

        return new SentMessage($message, $envelope);
    }

    private function post(string $raw): Response
    {
        try {
            return Http::timeout($this->timeoutSeconds)
                ->withToken($this->accessToken())
                ->acceptJson()
                ->post(self::SEND_ENDPOINT, ['raw' => $raw]);
        } catch (TransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException('Gmail API request failed: '.$e->getMessage(), 0, $e);
        }
    }

    /** A valid access token, from the cache or exchanged for the refresh token. */
    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout($this->timeoutSeconds)
            ->post(self::TOKEN_ENDPOINT, [
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
            ]);

        $token = (string) $response->json('access_token');
        if (! $response->successful() || $token === '') {
            $error = (string) ($response->json('error') ?? 'unknown');
            throw new TransportException(sprintf(
                'Google refused the Gmail refresh token (HTTP %d, %s: %s).%s',
                $response->status(),
                $error,
                $response->json('error_description') ?? '',
                $error === 'invalid_grant'
                    ? ' The token was revoked or expired: create a new GMAIL_REFRESH_TOKEN (DEPLOYMENT.md → "Email codes").'
                    : '',
            ));
        }

        // Kept a minute short of Google's expiry (normally an hour).
        $lifetime = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($lifetime));

        return $token;
    }

    public function __toString(): string
    {
        return 'gmail+api://gmail.googleapis.com';
    }
}
