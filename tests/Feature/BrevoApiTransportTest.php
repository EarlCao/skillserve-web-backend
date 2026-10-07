<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * MAIL_MAILER=brevo-api: email goes out over Brevo's transactional email API,
 * and a refusal is an error with Brevo's reason, never a silent success.
 */
class BrevoApiTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.brevo.api_key' => 'xkeysib-test',
            'mail.from.address' => 'no-reply@skillserve.example',
            'mail.from.name' => 'SkillServe',
        ]);
    }

    private function sendOne(): void
    {
        Mail::mailer('brevo-api')->html('<p>Your code</p>', function (Message $message): void {
            $message->to('juan@gmail.com', 'Juan')->subject('Your SkillServe verification code');
        });
    }

    public function test_a_message_is_sent_through_the_brevo_api(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<202610071200.1234@smtp-relay.mailin.fr>'], 201)]);

        $this->sendOne();

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'xkeysib-test')
                && $request['sender'] === ['email' => 'no-reply@skillserve.example', 'name' => 'SkillServe']
                && $request['to'] === [['email' => 'juan@gmail.com', 'name' => 'Juan']]
                && $request['subject'] === 'Your SkillServe verification code'
                && $request['htmlContent'] === '<p>Your code</p>';
        });
    }

    public function test_a_refused_message_raises_brevos_reason(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response([
            'code' => 'unauthorized',
            'message' => 'We have detected you are using an unrecognised IP address 203.0.113.7.',
        ], 401)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('HTTP 401: unauthorized We have detected you are using an unrecognised IP address');

        $this->sendOne();
    }

    public function test_the_mailer_refuses_to_start_without_an_api_key(): void
    {
        config(['services.brevo.api_key' => null]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('BREVO_API_KEY is not set');

        $this->sendOne();
    }
}
