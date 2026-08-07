<?php

namespace App\Shared\Swagger;

use OpenApi\Attributes as OA;

/**
 * Global OpenAPI metadata.
 *
 * NOTE: l5-swagger v11 configures an attribute-only analyser, so all
 * documentation is declared with PHP 8 attributes (not @OA docblocks).
 */
#[OA\Info(
    version: '1.0.0',
    title: 'SkillServe API',
    description: 'REST API for the SkillServe admin web system — a modular monolith (Laravel 13 + Sanctum). All responses use the standard envelope: { success, message, data, errors, meta }.',
    contact: new OA\Contact(email: 'group6@skillserve.test'),
)]
#[OA\Server(url: 'http://localhost:8000', description: 'Local development server')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'sanctum-token',
    description: 'Paste the Sanctum token returned by POST /api/auth/login.',
)]
#[OA\Schema(
    schema: 'ApiEnvelope',
    required: ['success', 'message'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Request successful.'),
        new OA\Property(property: 'data', description: 'Payload (object/array/null)', nullable: true),
        new OA\Property(property: 'errors', description: 'Field errors keyed by field', nullable: true, type: 'object'),
        new OA\Property(property: 'meta', type: 'object', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'System Administrator'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@skillserve.test'),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['super-admin']),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['manage administrators']),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'AuthPayload',
    properties: [
        new OA\Property(property: 'token', type: 'string', description: 'Sanctum plain-text token'),
        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
    ],
)]
class OpenApi
{
}
