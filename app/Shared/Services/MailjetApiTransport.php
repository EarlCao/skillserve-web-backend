<?php

namespace App\Shared\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Mailjet's Send API v3.1 (https://api.mailjet.com/v3.1/send) over HTTPS —
 * the production mailer for the 6-digit codes and every other email. Like the
 * other API transports it avoids SMTP, which the Render free tier blocks.
 *
 * Mailjet answers every message with a status; anything but "success" is
 * raised with Mailjet's own error text, so a refused email (unverified sender,
 * blocked account) shows up in the server log instead of failing silently.
 * Every email is also listed in Mailjet's dashboard.
 *
 * Enable with MAIL_MAILER=mailjet-api, MAILJET_API_KEY and MAILJET_SECRET_KEY.
 * MAIL_FROM_ADDRESS must be a sender address validated in Mailjet.
 */
class MailjetApiTransport implements TransportInterface
{
    private const ENDPOINT = 'https://api.mailjet.com/v3.1/send';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly int $timeoutSeconds = 15,
    ) {
        if (trim($this->apiKey) === '' || trim($this->secretKey) === '') {
            throw new TransportException('MAILJET_API_KEY and MAILJET_SECRET_KEY must be set for the mailjet-api mailer.');
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if (! $message instanceof Message) {
            throw new TransportException('Mailjet API transport only supports MIME messages.');
        }

        $envelope ??= Envelope::create($message);
        $from = $message->getFrom()[0] ?? $envelope->getSender();

        if ($from === null) {
            throw new TransportException('Mailjet API transport requires a From address (check MAIL_FROM_ADDRESS).');
        }

        $payload = array_filter([
            'From' => $this->address($from),
            'To' => $this->addresses($message->getTo() ?: $envelope->getRecipients()),
            'Cc' => $this->addresses($message->getCc()),
            'Bcc' => $this->addresses($message->getBcc()),
            'ReplyTo' => ($replyTo = $message->getReplyTo()[0] ?? null) ? $this->address($replyTo) : null,
            'Subject' => (string) $message->getSubject(),
            'TextPart' => $message->getTextBody() !== null ? (string) $message->getTextBody() : null,
            'HTMLPart' => $message->getHtmlBody() !== null ? (string) $message->getHtmlBody() : null,
        ]);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withBasicAuth($this->apiKey, $this->secretKey)
                ->acceptJson()
                ->post(self::ENDPOINT, ['Messages' => [$payload]]);
        } catch (\Throwable $e) {
            throw new TransportException('Mailjet API request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful() || $response->json('Messages.0.Status') !== 'success') {
            throw new TransportException($this->describe($response));
        }

        return new SentMessage($message, $envelope);
    }

    /** Mailjet's own reason, for the server log. */
    private function describe(Response $response): string
    {
        $errors = collect($response->json('Messages.0.Errors') ?? [])
            ->map(fn ($error): string => trim(($error['ErrorCode'] ?? '').' '.($error['ErrorMessage'] ?? '')))
            ->filter()
            ->implode('; ');

        return sprintf(
            'Mailjet API returned HTTP %d: %s',
            $response->status(),
            $errors !== '' ? $errors : substr((string) ($response->json('ErrorMessage') ?? $response->body()), 0, 500),
        );
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array<string, string>>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): array => $this->address($address), $addresses));
    }

    /** @return array<string, string> */
    private function address(Address $address): array
    {
        return array_filter(['Email' => $address->getAddress(), 'Name' => $address->getName()]);
    }

    public function __toString(): string
    {
        return 'mailjet+api://api.mailjet.com';
    }
}
