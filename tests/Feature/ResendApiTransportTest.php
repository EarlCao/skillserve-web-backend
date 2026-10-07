<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * MAIL_MAILER=resend-api: email goes out over Resend's email API, and a
 * refusal is an error with Resend's reason, never a silent success.
 */
class ResendApiTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.resend.key' => 're_test_key',
            'mail.from.address' => 'no-reply@skillserve.example',
            'mail.from.name' => 'SkillServe',
        ]);
    }

    private function sendOne(): void
    {
        Mail::mailer('resend-api')->html('<p>Your code</p>', function (Message $message): void {
            $message->to('juan@gmail.com', 'Juan')->subject('Your SkillServe verification code');
        });
    }

    public function test_a_message_is_sent_through_the_resend_api(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => '49a3999c-0ce1-4ea6-ab68-afcd6dc2e794'])]);

        $this->sendOne();

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.resend.com/emails'
                && $request->hasHeader('Authorization', 'Bearer re_test_key')
                && $request['from'] === 'SkillServe <no-reply@skillserve.example>'
                && $request['to'] === ['Juan <juan@gmail.com>']
                && $request['subject'] === 'Your SkillServe verification code'
                && $request['html'] === '<p>Your code</p>';
        });
    }

    public function test_a_refused_message_raises_resends_reason(): void
    {
        Http::fake(['api.resend.com/*' => Http::response([
            'statusCode' => 403,
            'name' => 'validation_error',
            'message' => 'The skillserve.example domain is not verified.',
        ], 403)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('HTTP 403: validation_error The skillserve.example domain is not verified.');

        $this->sendOne();
    }

    public function test_the_mailer_refuses_to_start_without_an_api_key(): void
    {
        config(['services.resend.key' => null]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('RESEND_API_KEY must be set');

        $this->sendOne();
    }
}
