<?php

namespace App\Shared\Swagger;

use OpenApi\Attributes as OA;

// NOTE: l5-swagger v11 configures an attribute-only analyser, so all
// documentation is declared with PHP 8 attributes (not @OA docblocks).
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
    // Schema-level example: Swagger UI uses it as the fallback for any
    // response that references the envelope without its own media-type
    // example. Every endpoint overrides this with a real-world example that
    // matches the live API (see the controllers' OA\Response annotations).
    example: [
        'success' => false,
        'message' => 'Something went wrong.',
        'data' => new \stdClass,
        'errors' => null,
        'meta' => [],
    ],
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
#[OA\Schema(
    schema: 'Administrator',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 2),
        new OA\Property(property: 'first_name', type: 'string', example: 'Jane'),
        new OA\Property(property: 'last_name', type: 'string', example: 'Doe'),
        new OA\Property(property: 'name', type: 'string', example: 'Jane Doe'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jane.doe@skillserve.test'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['admin']),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['view reports']),
        new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true, example: '2026-08-07T09:30:00+00:00'),
        new OA\Property(property: 'created_by', type: 'object', description: 'Administrator who created this account', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'integer', example: 1),
            new OA\Property(property: 'name', type: 'string', example: 'System Administrator'),
        ]),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-08-07T08:00:00+00:00'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-08-07T08:00:00+00:00'),
    ],
)]
#[OA\Schema(
    schema: 'Role',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'reports-manager'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Manages operational reports.'),
        new OA\Property(property: 'guard_name', type: 'string', example: 'web'),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['view reports']),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'Permission',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 5),
        new OA\Property(property: 'name', type: 'string', example: 'manage administrators'),
        new OA\Property(property: 'guard_name', type: 'string', example: 'web'),
        new OA\Property(property: 'module', type: 'string', example: 'Administrators'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class OpenApi {}
