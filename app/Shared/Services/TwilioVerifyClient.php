<?php

namespace App\Shared\Services;

use App\Shared\Exceptions\ApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Twilio Verify over its REST API: Twilio generates the code, sends it and
 * checks it. Used for the mobile sign-up and password-reset codes when
 * OTP_DRIVER=twilio.
 *
 * Twilio Verify sends email through a SendGrid account linked to the Verify
 * service (Twilio console → Verify → Email Integration), so both have to be
 * set up; every send and check is listed in Twilio → Monitor → Logs → Verify,
 * and every email in SendGrid → Activity.
 */
class TwilioVerifyClient
{
    private const BASE = 'https://verify.twilio.com/v2/Services/';

    /** Twilio: "Max send attempts reached" for this address. */
    private const TOO_MANY_SENDS = 60203;

    /** Twilio: "Max check attempts reached" for this code. */
    private const TOO_MANY_CHECKS = 60202;

    /** Whether the credentials and the Verify service are configured. */
    public function isConfigured(): bool
    {
        return filled(config('services.twilio.account_sid'))
            && filled(config('services.twilio.auth_token'))
            && filled(config('services.twilio.verify_service_sid'));
    }

    /**
     * Ask Twilio to email a code to $email. $substitutions fill the SendGrid
     * template (e.g. the name and why the code was sent); $templateId picks a
     * different template than the service's default.
     *
     * @param  array<string, string>  $substitutions
     */
    public function sendEmailCode(string $email, array $substitutions = [], ?string $templateId = null): void
    {
        $channelConfiguration = array_filter([
            'substitutions' => $substitutions ?: null,
            'template_id' => $templateId,
        ]);

        $response = $this->post('Verifications', array_filter([
            'To' => $email,
            'Channel' => 'email',
            'ChannelConfiguration' => $channelConfiguration ? json_encode($channelConfiguration) : null,
        ]));

        if ($response->successful()) {
            return;
        }

        if ($this->errorCode($response) === self::TOO_MANY_SENDS || $response->status() === 429) {
            throw new ApiException('Too many codes were sent to this address. Please wait 10 minutes and try again.', 429);
        }

        throw new RuntimeException($this->describe('send a code', $response));
    }

    /**
     * Whether $code is the code Twilio sent to $email. A code that expired,
     * was already used, or was never sent is simply not valid.
     */
    public function checkEmailCode(string $email, string $code): bool
    {
        $response = $this->post('VerificationCheck', ['To' => $email, 'Code' => $code]);

        if ($response->successful()) {
            return $response->json('status') === 'approved';
        }

        // No pending verification for this address (expired or used).
        if ($response->status() === 404) {
            return false;
        }

        if ($this->errorCode($response) === self::TOO_MANY_CHECKS || $response->status() === 429) {
            throw new ApiException('Too many incorrect attempts. Please request a new code.', 429);
        }

        throw new RuntimeException($this->describe('check a code', $response));
    }

    /** @param  array<string, string>  $form */
    private function post(string $resource, array $form): Response
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Twilio Verify is not configured: set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_VERIFY_SERVICE_SID.');
        }

        $url = self::BASE.config('services.twilio.verify_service_sid').'/'.$resource;

        try {
            return Http::asForm()
                ->timeout((int) config('services.twilio.timeout', 15))
                ->withBasicAuth((string) config('services.twilio.account_sid'), (string) config('services.twilio.auth_token'))
                ->post($url, $form);
        } catch (Throwable $e) {
            throw new RuntimeException('Twilio Verify could not be reached: '.$e->getMessage(), 0, $e);
        }
    }

    private function errorCode(Response $response): ?int
    {
        $code = $response->json('code');

        return is_numeric($code) ? (int) $code : null;
    }

    /** Twilio's own error code and message, for the server log. */
    private function describe(string $action, Response $response): string
    {
        return sprintf(
            'Twilio Verify could not %s (HTTP %d, code %s): %s',
            $action,
            $response->status(),
            $response->json('code') ?? 'none',
            $response->json('message') ?? substr((string) $response->body(), 0, 300),
        );
    }
}
