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
        new OA\Property(property: 'portfolio', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'skills', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'certifications', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'languages', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'average_rating', type: 'string', example: '4.50'),
        new OA\Property(property: 'total_reviews', type: 'integer'),
        new OA\Property(property: 'total_bookings', type: 'integer'),
        new OA\Property(property: 'completed_bookings', type: 'integer'),
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
