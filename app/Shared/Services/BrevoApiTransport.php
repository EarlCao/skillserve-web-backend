<?php

namespace App\Shared\Services;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Brevo transactional email transport over their REST API
 * (https://api.brevo.com/v3/smtp/email) instead of SMTP.
 *
 * Rationale: some hosting environments (e.g. Render free tier) silently
 * drop outbound connections on SMTP ports (25/465/587), making every mail
 * send hang for the socket timeout and then fail. The API path uses plain
 * HTTPS on 443 — the same egress the app already relies on for Google and
 * the database — and is unaffected.
 *
 * Enable with MAIL_MAILER=brevo-api and BREVO_API_KEY=<xkeysib-...>.
 */
class BrevoApiTransport implements TransportInterface
{
    private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 15,
    ) {
        if (trim($this->apiKey) === '') {
            throw new TransportException('BREVO_API_KEY is not set; the brevo-api mailer cannot send.');
        }
    }

    public function send(RawMessage $message, Envelope $envelope = null): SentMessage
    {
        if (! $message instanceof \Symfony\Component\Mime\Message) {
            throw new TransportException('Brevo API transport only supports MIME messages.');
        }

        $envelope ??= Envelope::create($message);

        $from = $message->getFrom()[0]
            ?? $envelope->getSender();

        if ($from === null) {
            throw new TransportException('Brevo API transport requires a From address (check MAIL_FROM_ADDRESS).');
        }

        $payload = [
            'sender' => [
                'email' => $from->getAddress(),
                'name' => $from->getName() !== '' ? $from->getName() : $from->getAddress(),
            ],
            'to' => array_values(array_map(
                static fn ($address) => [
                    'email' => $address->getAddress(),
                    'name' => $address->getName() !== '' ? $address->getName() : null,
                ],
                $message->getTo() ?: $envelope->getRecipients(),
            )),
            'subject' => (string) $message->getSubject(),
        ];

        if (($html = $message->getHtmlBody()) !== null) {
            $payload['htmlContent'] = $html;
        }
        if (($text = $message->getTextBody()) !== null) {
            $payload['textContent'] = $text;
        }

        if ($replyTo = $message->getReplyTo()[0] ?? null) {
            $payload['replyTo'] = ['email' => $replyTo->getAddress()];
        }
        if ($cc = $message->getCc()) {
            $payload['cc'] = array_map(static fn ($a) => ['email' => $a->getAddress()], $cc);
        }
        if ($bcc = $message->getBcc()) {
            $payload['bcc'] = array_map(static fn ($a) => ['email' => $a->getAddress()], $bcc);
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders([
                    'api-key' => $this->apiKey,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ])
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            throw new TransportException('Brevo API request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new TransportException(sprintf(
                'Brevo API returned HTTP %d: %s',
                $response->status(),
                substr((string) $response->body(), 0, 500),
            ));
        }

        return new SentMessage($message, $envelope);
    }

    public function __toString(): string
    {
        return 'brevo+api://api.brevo.com';
    }
}
