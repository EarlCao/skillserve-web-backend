<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * MAIL_MAILER=mailjet-api: email goes out over Mailjet's Send API v3.1, and a
 * refusal is an error with Mailjet's reason, never a silent success.
 */
class MailjetApiTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mailjet.key' => 'mj-key',
            'services.mailjet.secret' => 'mj-secret',
            'mail.from.address' => 'skillserve.app@gmail.com',
            'mail.from.name' => 'SkillServe',
        ]);
    }

    private function sendOne(): void
    {
        Mail::mailer('mailjet-api')->html('<p>Your code</p>', function (Message $message): void {
            $message->to('juan@gmail.com', 'Juan')->subject('Your SkillServe verification code');
        });
    }

    public function test_a_message_is_sent_through_the_mailjet_api(): void
    {
        Http::fake(['api.mailjet.com/*' => Http::response(['Messages' => [['Status' => 'success']]])]);

        $this->sendOne();

        Http::assertSent(function (Request $request): bool {
            $message = $request['Messages'][0];

            return $request->url() === 'https://api.mailjet.com/v3.1/send'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('mj-key:mj-secret'))
                && $message['From'] === ['Email' => 'skillserve.app@gmail.com', 'Name' => 'SkillServe']
                && $message['To'] === [['Email' => 'juan@gmail.com', 'Name' => 'Juan']]
                && $message['Subject'] === 'Your SkillServe verification code'
                && $message['HTMLPart'] === '<p>Your code</p>';
        });
    }

    public function test_a_refused_message_raises_mailjets_reason(): void
    {
        Http::fake(['api.mailjet.com/*' => Http::response(['Messages' => [[
            'Status' => 'error',
            'Errors' => [['ErrorCode' => 'send-0003', 'ErrorMessage' => 'The sender address is not validated.']],
        ]]], 400)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('send-0003 The sender address is not validated.');

        $this->sendOne();
    }
}
