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
 * Resend's email API (https://api.resend.com/emails) over HTTPS — the
 * production mailer for the 6-digit codes and every other email. Like the
 * other API transports it avoids SMTP, which the Render free tier blocks.
 *
 * Anything but a 2xx is raised with Resend's own error name and message, so a
 * refused email (unverified domain, daily limit reached) shows up in the
 * server log instead of failing silently. Every email is also listed in
 * Resend's dashboard under Emails.
 *
 * Enable with MAIL_MAILER=resend-api and RESEND_API_KEY. MAIL_FROM_ADDRESS
 * must be on a domain verified in Resend; without one Resend only delivers to
 * the address the Resend account was created with.
 */
class ResendApiTransport implements TransportInterface
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 15,
    ) {
        if (trim($this->apiKey) === '') {
            throw new TransportException('RESEND_API_KEY must be set for the resend-api mailer.');
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if (! $message instanceof Message) {
            throw new TransportException('Resend API transport only supports MIME messages.');
        }

        $envelope ??= Envelope::create($message);
        $from = $message->getFrom()[0] ?? $envelope->getSender();

        if ($from === null) {
            throw new TransportException('Resend API transport requires a From address (check MAIL_FROM_ADDRESS).');
        }

        $payload = array_filter([
            'from' => $this->address($from),
            'to' => $this->addresses($message->getTo() ?: $envelope->getRecipients()),
            'cc' => $this->addresses($message->getCc()),
            'bcc' => $this->addresses($message->getBcc()),
            'reply_to' => $this->addresses($message->getReplyTo()),
            'subject' => (string) $message->getSubject(),
            'text' => $message->getTextBody() !== null ? (string) $message->getTextBody() : null,
            'html' => $message->getHtmlBody() !== null ? (string) $message->getHtmlBody() : null,
        ]);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            throw new TransportException('Resend API request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new TransportException($this->describe($response));
        }

        $sent = new SentMessage($message, $envelope);
        if (is_string($id = $response->json('id'))) {
            $sent->setMessageId($id);
        }

        return $sent;
    }

    /** Resend's own reason, for the server log. */
    private function describe(Response $response): string
    {
        $reason = trim(($response->json('name') ?? '').' '.($response->json('message') ?? ''));

        return sprintf(
            'Resend API returned HTTP %d: %s',
            $response->status(),
            $reason !== '' ? $reason : substr($response->body(), 0, 500),
        );
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): string => $this->address($address), $addresses));
    }

    /** "Name <email>", the form Resend expects. */
    private function address(Address $address): string
    {
        $name = str_replace(['"', '<', '>'], '', $address->getName());

        return $name !== '' ? sprintf('%s <%s>', $name, $address->getAddress()) : $address->getAddress();
    }

    public function __toString(): string
    {
        return 'resend+api://api.resend.com';
    }
}
