<?php

namespace App\Shared\Swagger;

use OpenApi\Attributes as OA;

/**
 * Documentation only: endpoints with no controller of their own — the health
 * check is a route closure (routes/api.php) and channel authorisation is
 * Laravel's broadcasting route (bootstrap/app.php).
 */
final class SystemEndpoints
{
    #[OA\Get(
        path: '/api/health',
        summary: 'Health check',
        description: 'Public. Reports whether the database and the upload storage (storage/app, the Render persistent disk in production) are usable. Never includes error details; those go to the server log. `otp` says whether the 6-digit codes can be sent (Twilio Verify configured, or a real mailer) without failing the check.',
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'Everything is up', content: new OA\JsonContent(
                example: ['status' => 'ok', 'timestamp' => '2026-10-06T08:00:00+00:00', 'services' => ['database' => ['status' => 'up'], 'storage' => ['status' => 'up'], 'otp' => ['status' => 'up', 'driver' => 'twilio']]],
            )),
            new OA\Response(response: 503, description: 'Degraded: a service is down', content: new OA\JsonContent(
                example: ['status' => 'degraded', 'timestamp' => '2026-10-06T08:00:00+00:00', 'services' => ['database' => ['status' => 'down'], 'storage' => ['status' => 'up'], 'otp' => ['status' => 'up', 'driver' => 'twilio']]],
            )),
        ],
    )]
    public function health(): void {}

    #[OA\Post(
        path: '/api/broadcasting/auth',
        summary: 'Authorise a private or presence channel',
        description: 'Called by the Reverb (Pusher protocol) client before subscribing to a private-* or presence-* channel. Also answers GET. Admin web and mobile tokens are both accepted; the channel rules are in routes/channels.php.',
        tags: ['System'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['socket_id', 'channel_name'],
            properties: [
                new OA\Property(property: 'socket_id', type: 'string', example: '55124926.941300434'),
                new OA\Property(property: 'channel_name', type: 'string', example: 'private-App.Models.User.12'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Signed authorisation for the channel', content: new OA\JsonContent(
                example: ['auth' => 'skillserve:3f2c…'],
            )),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not allowed on this channel'),
        ],
    )]
    public function broadcastingAuth(): void {}
}
