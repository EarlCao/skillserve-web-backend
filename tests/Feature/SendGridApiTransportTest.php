<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * MAIL_MAILER=sendgrid-api: every email (admin password reset, notifications)
 * goes out over SendGrid's HTTPS API.
 */
class SendGridApiTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sendgrid.api_key' => 'SG.test-key', 'mail.from.address' => 'no-reply@skillserve.test', 'mail.from.name' => 'SkillServe']);
    }

    private function sendOne(): void
    {
        Mail::mailer('sendgrid-api')->html('<p>Hello</p>', function (Message $message): void {
            $message->to('juan@gmail.com', 'Juan')->subject('Reset your password');
        });
    }

    public function test_a_message_is_sent_through_the_sendgrid_api(): void
    {
        Http::fake(['api.sendgrid.com/*' => Http::response('', 202)]);

        $this->sendOne();

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
                && $request->hasHeader('Authorization', 'Bearer SG.test-key')
                && $request['from'] === ['email' => 'no-reply@skillserve.test', 'name' => 'SkillServe']
                && $request['personalizations'][0]['to'] === [['email' => 'juan@gmail.com', 'name' => 'Juan']]
                && $request['subject'] === 'Reset your password'
                && $request['content'][0] === ['type' => 'text/html', 'value' => '<p>Hello</p>'];
        });
    }

    public function test_a_refusal_from_sendgrid_is_an_error_not_a_silent_success(): void
    {
        Http::fake(['api.sendgrid.com/*' => Http::response(['errors' => [['message' => 'The from address does not match a verified Sender Identity.']]], 403)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('verified Sender Identity');

        $this->sendOne();
    }
}
