<?php

namespace App\Shared\Services;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * SendGrid's v3 Mail Send API (https://api.sendgrid.com/v3/mail/send) over
 * HTTPS instead of SMTP, for the same reason as {@see BrevoApiTransport}: the
 * Render free tier drops outbound SMTP connections. The SendGrid account that
 * Twilio Verify sends the codes through can send every other email too, and
 * each message is listed in SendGrid → Activity.
 *
 * Enable with MAIL_MAILER=sendgrid-api and SENDGRID_API_KEY=<SG....>.
 * MAIL_FROM_ADDRESS must be a verified sender in SendGrid.
 */
class SendGridApiTransport implements TransportInterface
{
    private const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 15,
    ) {
        if (trim($this->apiKey) === '') {
            throw new TransportException('SENDGRID_API_KEY is not set; the sendgrid-api mailer cannot send.');
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if (! $message instanceof Message) {
            throw new TransportException('SendGrid API transport only supports MIME messages.');
        }

        $envelope ??= Envelope::create($message);
        $from = $message->getFrom()[0] ?? $envelope->getSender();

        if ($from === null) {
            throw new TransportException('SendGrid API transport requires a From address (check MAIL_FROM_ADDRESS).');
        }

        $personalization = array_filter([
            'to' => $this->addresses($message->getTo() ?: $envelope->getRecipients()),
            'cc' => $this->addresses($message->getCc()),
            'bcc' => $this->addresses($message->getBcc()),
        ]);

        // SendGrid requires text/plain before text/html.
        $content = [];
        if (($text = $message->getTextBody()) !== null) {
            $content[] = ['type' => 'text/plain', 'value' => (string) $text];
        }
        if (($html = $message->getHtmlBody()) !== null) {
            $content[] = ['type' => 'text/html', 'value' => (string) $html];
        }

        $payload = array_filter([
            'personalizations' => [$personalization],
            'from' => $this->address($from),
            'reply_to' => ($replyTo = $message->getReplyTo()[0] ?? null) ? $this->address($replyTo) : null,
            'subject' => (string) $message->getSubject(),
            'content' => $content ?: [['type' => 'text/plain', 'value' => ' ']],
        ]);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            throw new TransportException('SendGrid API request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new TransportException(sprintf(
                'SendGrid API returned HTTP %d: %s',
                $response->status(),
                substr((string) $response->body(), 0, 500),
            ));
        }

        return new SentMessage($message, $envelope);
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
        return array_filter(['email' => $address->getAddress(), 'name' => $address->getName()]);
    }

    public function __toString(): string
    {
        return 'sendgrid+api://api.sendgrid.com';
    }
}
