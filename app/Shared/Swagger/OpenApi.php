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
        'message' => 'The given data was invalid.',
        'data' => new \stdClass,
        'errors' => [
            'email' => ['The email field is required.'],
        ],
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
    schema: 'PaginationMeta',
    properties: [
        new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
    ],
)]
#[OA\Schema(
    schema: 'Pagination',
    properties: [
        new OA\Property(property: 'total', type: 'integer', example: 2),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 1),
        new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'to', type: 'integer', nullable: true, example: 2),
    ],
)]
#[OA\Schema(
    schema: 'ClientUser',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 10),
        new OA\Property(property: 'first_name', type: 'string', example: 'Alex'),
        new OA\Property(property: 'last_name', type: 'string', example: 'Customer'),
        new OA\Property(property: 'name', type: 'string', example: 'Alex Customer'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'alex@example.com'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+639171234567'),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'birthday', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'role_id', type: 'integer', example: 4, description: 'users.role_id → roles.id: 1 super-admin, 2 admin, 3 provider, 4 customer'),
        new OA\Property(property: 'role_name', type: 'string', enum: ['super-admin', 'admin', 'provider', 'customer'], example: 'customer'),
        new OA\Property(property: 'user_type', type: 'string', enum: ['customer', 'provider', 'admin'], example: 'customer', description: 'Derived from role_id; kept for existing clients'),
        new OA\Property(property: 'email_verified', type: 'boolean', example: true),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientAuthPayload',
    properties: [
        new OA\Property(property: 'token', type: 'string', description: 'Sanctum plain-text access token'),
        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'refresh_token', type: 'string', description: 'Opaque refresh token; returned only once'),
        new OA\Property(property: 'refresh_expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'user', ref: '#/components/schemas/ClientUser'),
    ],
)]
#[OA\Schema(
    schema: 'ClientPerson',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
    ],
)]
#[OA\Schema(
    schema: 'ClientSubcategory',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'category_id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientCategory',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'provider_count', type: 'integer', description: 'Verified providers with public services in this category (list endpoint only)'),
        new OA\Property(property: 'subcategories', type: 'array', nullable: true, items: new OA\Items(ref: '#/components/schemas/ClientSubcategory')),
    ],
)]
#[OA\Schema(
    schema: 'ClientService',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'price', type: 'string', nullable: true, example: '125.00'),
        new OA\Property(property: 'price_type', type: 'string'),
        new OA\Property(property: 'currency', type: 'string', example: 'PHP'),
        new OA\Property(property: 'duration', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'average_rating', type: 'string', example: '4.50'),
        new OA\Property(property: 'total_reviews', type: 'integer'),
        new OA\Property(property: 'total_bookings', type: 'integer'),
        new OA\Property(property: 'category', ref: '#/components/schemas/ClientPerson', nullable: true),
        new OA\Property(property: 'subcategory', ref: '#/components/schemas/ClientPerson', nullable: true),
        new OA\Property(property: 'provider', ref: '#/components/schemas/ClientProviderSummary', nullable: true),
        new OA\Property(property: 'reviews', type: 'array', nullable: true, items: new OA\Items(ref: '#/components/schemas/ClientReview')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ProviderService',
    description: 'A provider\'s own service with its moderation state.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ClientService'),
        new OA\Schema(properties: [
            new OA\Property(property: 'category_id', type: 'integer'),
            new OA\Property(property: 'subcategory_id', type: 'integer', nullable: true),
            new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'archived']),
            new OA\Property(property: 'approval_status', type: 'string', enum: ['pending', 'approved', 'rejected']),
            new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
            new OA\Property(property: 'is_featured', type: 'boolean'),
            new OA\Property(property: 'is_hidden', type: 'boolean'),
            new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        ]),
    ],
)]
#[OA\Schema(
    schema: 'ProviderServiceInput',
    description: 'Required on create: title, category_id, price, price_type. All fields optional on update.',
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', maxLength: 5000, nullable: true),
        new OA\Property(property: 'category_id', type: 'integer', description: 'An enabled service category'),
        new OA\Property(property: 'subcategory_id', type: 'integer', nullable: true, description: 'An enabled subcategory of category_id'),
        new OA\Property(property: 'price', type: 'number', format: 'float', minimum: 0, description: 'Amount in Philippine pesos'),
        new OA\Property(property: 'price_type', type: 'string', enum: ['fixed', 'hourly', 'custom']),
        new OA\Property(property: 'duration', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'location', type: 'string', maxLength: 255, nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ProviderProfile',
    description: 'The signed-in provider\'s own profile.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ClientProvider'),
        new OA\Schema(properties: [
            new OA\Property(property: 'user_id', type: 'integer'),
            new OA\Property(property: 'verification_status', type: 'string', enum: ['pending', 'verified', 'rejected', 'additional_info_required']),
            new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
            new OA\Property(property: 'is_featured', type: 'boolean'),
            new OA\Property(property: 'is_suspended', type: 'boolean'),
        ]),
    ],
)]
#[OA\Schema(
    schema: 'ProviderProfileEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProviderProfile')])],
)]
#[OA\Schema(
    schema: 'ProviderServiceEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProviderService')])],
)]
#[OA\Schema(
    schema: 'ProviderServiceListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProviderService')), new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta')])],
)]
#[OA\Schema(
    schema: 'ClientProviderSummary',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'business_name', type: 'string'),
        new OA\Property(property: 'average_rating', type: 'string', example: '4.50'),
        new OA\Property(property: 'total_reviews', type: 'integer', nullable: true),
        new OA\Property(property: 'total_bookings', type: 'integer', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientProvider',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'business_name', type: 'string'),
        new OA\Property(property: 'bio', type: 'string', nullable: true),
        new OA\Property(property: 'specialization', type: 'string', nullable: true),
        new OA\Property(property: 'experience_years', type: 'integer', nullable: true),
        new OA\Property(property: 'hourly_rate', type: 'string', nullable: true, example: '500.00'),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'website', type: 'string', nullable: true),
        new OA\Property(property: 'social_links', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'portfolio', type: 'array', nullable: true, description: 'Work samples, present on the provider detail response', items: new OA\Items(ref: '#/components/schemas/ClientPortfolioItem')),
        new OA\Property(property: 'badges', type: 'array', nullable: true, description: 'Recognition badges, present on the provider detail response', items: new OA\Items(ref: '#/components/schemas/ClientBadge')),
        new OA\Property(property: 'skills', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'certifications', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'languages', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'average_rating', type: 'string', example: '4.50'),
        new OA\Property(property: 'total_reviews', type: 'integer'),
        new OA\Property(property: 'total_bookings', type: 'integer'),
        new OA\Property(property: 'completed_bookings', type: 'integer'),
        new OA\Property(property: 'is_featured', type: 'boolean', description: 'Highlighted by administrators'),
        new OA\Property(property: 'is_accepting_bookings', type: 'boolean', description: 'Whether the provider is taking new bookings'),
        new OA\Property(property: 'availability', type: 'array', nullable: true, description: 'Published weekly hours, present on the provider detail response. An empty array means no published hours, which does not restrict booking times.', items: new OA\Items(ref: '#/components/schemas/ProviderAvailabilityWindow')),
        new OA\Property(property: 'verification_status', type: 'string', example: 'verified'),
        new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'starting_price', type: 'string', nullable: true, example: '1500.00', description: 'Lowest price (PHP) across the provider\'s public services'),
        new OA\Property(property: 'primary_category', type: 'string', nullable: true, example: 'Appliance Repair', description: 'Category with the most public services'),
        new OA\Property(property: 'services', type: 'array', nullable: true, items: new OA\Items(ref: '#/components/schemas/ClientService')),
        new OA\Property(property: 'reviews', type: 'array', nullable: true, items: new OA\Items(ref: '#/components/schemas/ClientReview')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientReview',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'booking', ref: '#/components/schemas/ClientBookingSummary', nullable: true),
        new OA\Property(property: 'reviewer', ref: '#/components/schemas/ClientPerson', nullable: true),
        new OA\Property(property: 'provider', ref: '#/components/schemas/ClientProviderSummary', nullable: true),
        new OA\Property(property: 'service', ref: '#/components/schemas/ClientServiceSummary', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientBookingSummary',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'booking_number', type: 'string'),
    ],
)]
#[OA\Schema(
    schema: 'ClientServiceSummary',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'title', type: 'string'),
    ],
)]
#[OA\Schema(
    schema: 'ClientBooking',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'booking_number', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'payment_status', type: 'string'),
        new OA\Property(property: 'service_price', type: 'string', example: '125.00'),
        new OA\Property(property: 'total_price', type: 'string', example: '125.00'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'payment_method', type: 'string', nullable: true),
        new OA\Property(property: 'cancellation_payment_policy', type: 'string', nullable: true),
        new OA\Property(property: 'client_notes', type: 'string', nullable: true),
        new OA\Property(property: 'cancellation_reason', type: 'string', nullable: true),
        new OA\Property(property: 'scheduled_date', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'scheduled_end_date', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'confirmed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_reviewed', type: 'boolean'),
        new OA\Property(property: 'service', ref: '#/components/schemas/ClientService', nullable: true),
        new OA\Property(property: 'provider', ref: '#/components/schemas/ClientProviderSummary', nullable: true),
        new OA\Property(property: 'review', ref: '#/components/schemas/ClientReview', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'BookingMessage',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'booking_id', type: 'integer'),
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'sender', ref: '#/components/schemas/ClientPerson', nullable: true),
        new OA\Property(property: 'receiver', ref: '#/components/schemas/ClientPerson', nullable: true),
        new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientSupportTicket',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'ticket_number', type: 'string'),
        new OA\Property(property: 'subject', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'priority', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'resolution_note', type: 'string', nullable: true),
        new OA\Property(property: 'requester', ref: '#/components/schemas/ClientPerson', nullable: true),
        new OA\Property(property: 'messages', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'body', type: 'string'),
            new OA\Property(property: 'author', ref: '#/components/schemas/ClientPerson', nullable: true),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        ])),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientNotificationData',
    properties: [
        new OA\Property(property: 'type', type: 'string', nullable: true),
        new OA\Property(property: 'title', type: 'string', nullable: true),
        new OA\Property(property: 'message', type: 'string', nullable: true),
        new OA\Property(property: 'body', type: 'string', nullable: true),
        new OA\Property(property: 'announcement_id', type: 'integer', nullable: true),
        new OA\Property(property: 'booking_id', type: 'integer', nullable: true),
        new OA\Property(property: 'ticket_id', type: 'integer', nullable: true),
        new OA\Property(property: 'ticket_number', type: 'string', nullable: true),
        new OA\Property(property: 'action', type: 'string', nullable: true, description: 'Service moderation action: approved, rejected, updated, hidden, unhidden, featured, unfeatured, deleted', example: 'approved'),
        new OA\Property(property: 'service_id', type: 'integer', nullable: true),
        new OA\Property(property: 'service_title', type: 'string', nullable: true),
        new OA\Property(property: 'reason', type: 'string', nullable: true, description: 'Rejection reason or approval note'),
    ],
)]
#[OA\Schema(
    schema: 'ClientNotification',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'title', type: 'string', nullable: true),
        new OA\Property(property: 'message', type: 'string', nullable: true),
        new OA\Property(property: 'data', ref: '#/components/schemas/ClientNotificationData'),
        new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientAuthEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientAuthPayload')])],
)]
#[OA\Schema(
    schema: 'ClientUserEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientUser')])],
)]
#[OA\Schema(
    schema: 'ClientPortfolioItem',
    description: "A work sample on a provider's public profile.",
    properties: [
        new OA\Property(property: 'portfolio_id', type: 'integer'),
        new OA\Property(property: 'provider_id', type: 'integer'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'image', type: 'string', nullable: true, description: 'Absolute image URL'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientPortfolioEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientPortfolioItem')])],
)]
#[OA\Schema(
    schema: 'ClientPortfolioListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientPortfolioItem'))])],
)]
#[OA\Schema(
    schema: 'ProviderAvailabilityWindow',
    description: "One weekday window of a provider's published hours. Times are wall clock in the platform's timezone.",
    properties: [
        new OA\Property(property: 'day_of_week', type: 'integer', minimum: 0, maximum: 6, description: '0 = Sunday … 6 = Saturday'),
        new OA\Property(property: 'day', type: 'string', example: 'Monday'),
        new OA\Property(property: 'start_time', type: 'string', example: '09:00'),
        new OA\Property(property: 'end_time', type: 'string', example: '17:00'),
    ],
)]
#[OA\Schema(
    schema: 'ProviderAvailabilityEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', properties: [
        new OA\Property(property: 'is_accepting_bookings', type: 'boolean'),
        new OA\Property(property: 'availability', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProviderAvailabilityWindow')),
    ], type: 'object')])],
)]
#[OA\Schema(
    schema: 'ClientBadge',
    description: 'A recognition badge. `earned` is false for badges the provider has not been awarded yet.',
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'top_rated'),
        new OA\Property(property: 'title', type: 'string', example: 'Top Rated'),
        new OA\Property(property: 'criteria', type: 'string', nullable: true),
        new OA\Property(property: 'color', type: 'string', example: 'primary'),
        new OA\Property(property: 'earned', type: 'boolean'),
        new OA\Property(property: 'earned_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientBadgeSetEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', properties: [
        new OA\Property(property: 'earned', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientBadge')),
        new OA\Property(property: 'available', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientBadge')),
    ], type: 'object')])],
)]
#[OA\Schema(
    schema: 'ClientPreference',
    description: "A mobile account's notification, privacy and application settings.",
    properties: [
        new OA\Property(property: 'booking_notifications', type: 'boolean'),
        new OA\Property(property: 'service_notifications', type: 'boolean'),
        new OA\Property(property: 'message_notifications', type: 'boolean'),
        new OA\Property(property: 'announcement_notifications', type: 'boolean'),
        new OA\Property(property: 'private_profile', type: 'boolean'),
        new OA\Property(property: 'activity_personalization', type: 'boolean'),
        new OA\Property(property: 'reduce_motion', type: 'boolean'),
        new OA\Property(property: 'theme', type: 'string', enum: ['light', 'dark', 'system']),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientPreferenceEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientPreference')])],
)]
#[OA\Schema(
    schema: 'ClientPendingRegistration',
    description: 'A mobile sign-up parked until its emailed code is confirmed. No account and no session exist yet.',
    properties: [
        new OA\Property(property: 'verification_required', type: 'boolean', example: true),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string'),
        new OA\Property(property: 'user_type', type: 'string', enum: ['customer', 'provider']),
        new OA\Property(property: 'code_expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'registration_expires_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ClientPendingRegistrationEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientPendingRegistration')])],
)]
#[OA\Schema(
    schema: 'ClientGoogleRegistrationDraft',
    description: 'Prefill for the "complete your profile" screen shown when a Google account has no SkillServe account yet.',
    properties: [
        new OA\Property(property: 'registration_required', type: 'boolean', example: true),
        new OA\Property(property: 'google', properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email'),
            new OA\Property(property: 'first_name', type: 'string'),
            new OA\Property(property: 'last_name', type: 'string'),
            new OA\Property(property: 'picture', type: 'string', nullable: true),
        ], type: 'object'),
    ],
)]
#[OA\Schema(
    schema: 'ClientGoogleAuthPayload',
    description: 'Either an issued session (registration_required=false) or a sign-up draft (registration_required=true).',
    oneOf: [
        new OA\Schema(allOf: [new OA\Schema(ref: '#/components/schemas/ClientAuthPayload'), new OA\Schema(properties: [new OA\Property(property: 'registration_required', type: 'boolean', example: false)], type: 'object')]),
        new OA\Schema(ref: '#/components/schemas/ClientGoogleRegistrationDraft'),
    ],
)]
#[OA\Schema(
    schema: 'ClientGoogleAuthEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientGoogleAuthPayload')])],
)]
#[OA\Schema(
    schema: 'ClientCategoryEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientCategory')])],
)]
#[OA\Schema(
    schema: 'ClientCategoryListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientCategory')), new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta')])],
)]
#[OA\Schema(
    schema: 'ClientServiceEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientService')])],
)]
#[OA\Schema(
    schema: 'ClientServiceListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientService')), new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta')])],
)]
#[OA\Schema(
    schema: 'ClientProviderEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientProvider')])],
)]
#[OA\Schema(
    schema: 'ClientProviderListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientProvider')), new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta')])],
)]
#[OA\Schema(
    schema: 'ClientBookingEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientBooking')])],
)]
#[OA\Schema(
    schema: 'ClientBookingListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientBooking')), new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta')])],
)]
#[OA\Schema(
    schema: 'ClientReviewEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientReview')])],
)]
#[OA\Schema(
    schema: 'ClientReviewListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientReview')), new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta')])],
)]
#[OA\Schema(
    schema: 'BookingMessageEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BookingMessage')])],
)]
#[OA\Schema(
    schema: 'BookingMessageListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BookingMessage')), new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta')])],
)]
#[OA\Schema(
    schema: 'ClientSupportTicketEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientSupportTicket')])],
)]
#[OA\Schema(
    schema: 'ClientSupportTicketListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientSupportTicket')), new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta')])],
)]
#[OA\Schema(
    schema: 'ClientNotificationEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClientNotification')])],
)]
#[OA\Schema(
    schema: 'ClientNotificationListEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientNotification')), new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta')])],
)]
#[OA\Schema(
    schema: 'ClientUnreadCountEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'unread_count', type: 'integer')])])],
)]
#[OA\Schema(
    schema: 'ClientReadAllEnvelope',
    allOf: [new OA\Schema(ref: '#/components/schemas/ApiEnvelope'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'updated_count', type: 'integer')])])],
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
#[OA\Schema(
    schema: 'ServiceCategory',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Home Maintenance'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Plumbing, electrical and painting services.'),
        new OA\Property(property: 'status', type: 'string', enum: ['enabled', 'disabled'], example: 'enabled'),
        new OA\Property(property: 'subcategories_count', type: 'integer', example: 3),
        new OA\Property(property: 'subcategories', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceSubcategory'), nullable: true),
        new OA\Property(property: 'created_by', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'integer', example: 1),
            new OA\Property(property: 'name', type: 'string', example: 'System Administrator'),
        ]),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-08-12T08:00:00+00:00'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-08-12T08:00:00+00:00'),
    ],
)]
#[OA\Schema(
    schema: 'ServiceSubcategory',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'category_id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Plumbing'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Pipe installation, repair and maintenance.'),
        new OA\Property(property: 'status', type: 'string', enum: ['enabled', 'disabled'], example: 'enabled'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-08-12T08:00:00+00:00'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-08-12T08:00:00+00:00'),
    ],
)]
class OpenApi {}
