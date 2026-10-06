<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * MAIL_MAILER=gmail-api: email is sent as the authorised Gmail account over
 * the Gmail API, with the access token exchanged from the refresh token.
 */
class GmailApiTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.gmail.client_id' => 'client-id.apps.googleusercontent.com',
            'services.gmail.client_secret' => 'client-secret',
            'services.gmail.refresh_token' => 'refresh-token',
            'mail.from.address' => 'earlcao12345.ec@gmail.com',
            'mail.from.name' => 'SkillServe',
        ]);
    }

    private function sendOne(string $to = 'juan@gmail.com'): void
    {
        Mail::mailer('gmail-api')->html('<p>Your code is 123456</p>', function (Message $message) use ($to): void {
            $message->to($to, 'Juan')->subject('Your SkillServe verification code');
        });
    }

    private static function decode(string $raw): string
    {
        return quoted_printable_decode((string) base64_decode(strtr($raw, '-_', '+/')));
    }

    public function test_the_message_is_sent_through_gmail_with_a_cached_access_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
            'gmail.googleapis.com/*' => Http::response(['id' => 'msg-1', 'labelIds' => ['SENT']]),
        ]);

        $this->sendOne();
        $this->sendOne('maria@gmail.com');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-token');

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send') {
                return false;
            }
            $mime = self::decode($request['raw']);

            return $request->hasHeader('Authorization', 'Bearer ya29.token')
                && str_contains($mime, 'To: Juan <juan@gmail.com>')
                && str_contains($mime, 'From: SkillServe <earlcao12345.ec@gmail.com>')
                && str_contains($mime, 'Subject: Your SkillServe verification code')
                && str_contains($mime, 'Your code is 123456');
        });

        // One token exchange serves both emails.
        $this->assertSame(1, Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com'))->count());
    }

    public function test_a_revoked_refresh_token_says_how_to_fix_it(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('invalid_grant: Token has been expired or revoked.). The token was revoked or expired: create a new GMAIL_REFRESH_TOKEN');

        $this->sendOne();
    }

    public function test_a_stale_access_token_is_replaced_once(): void
    {
        Cache::put('gmail-api:access-token', 'stale', now()->addHour());
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3599]),
            'gmail.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Invalid Credentials']], 401)
                ->push(['id' => 'msg-2']),
        ]);

        $this->sendOne();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'gmail.googleapis.com') && $request->hasHeader('Authorization', 'Bearer fresh'));
    }

    public function test_gmail_refusing_the_message_raises_googles_reason(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
            'gmail.googleapis.com/*' => Http::response(['error' => ['message' => 'Request had insufficient authentication scopes.']], 403),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Gmail API returned HTTP 403: Request had insufficient authentication scopes.');

        $this->sendOne();
    }
}
