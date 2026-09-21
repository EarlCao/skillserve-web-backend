<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Modules\Bookings\Services\BookingRules;
use App\Modules\ClientAuthentication\Services\ClientProfileService;
use App\Shared\Resources\BaseResource;

/**
 * A booking as its provider sees it.
 *
 * Mirrors {@see ClientBookingResource} but swaps the provider block for the
 * customer the job is for: the provider needs the name, the number to call
 * and the address to show up, and nothing else from that account.
 */
class ProviderBookingResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_amount' => $this->refunded_amount,
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'refund_reason' => $this->refund_reason,
            'service_price' => $this->service_price,
            'total_price' => $this->total_price,
            'platform_fee' => $this->platform_fee,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'client_notes' => $this->client_notes,
            'provider_notes' => $this->provider_notes,
            'service_address' => $this->service_address,
            'contact_phone' => $this->contact_phone,
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_fee' => $this->cancellation_fee,
            // The cancellation rule and what cancelling now would cost; only
            // while this side can still cancel.
            'cancellation_policy' => $this->when(
                in_array($this->status, ['confirmed'], true),
                fn () => app(BookingRules::class)->policyFor($this->resource, 'provider'),
            ),
            'scheduled_date' => $this->scheduled_date?->toIso8601String(),
            'scheduled_end_date' => $this->scheduled_end_date?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'rescheduled_at' => $this->rescheduled_at?->toIso8601String(),
            'is_reviewed' => $this->is_reviewed,
            'service' => $this->whenLoaded('service', fn () => new ClientServiceResource($this->service)),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
                // The booking's own contact number wins; the account phone is
                // the fallback when the customer left the form field empty.
                'phone' => $this->contact_phone ?: $this->client->phone,
                'profile_picture' => ClientProfileService::photoUrl($this->client->profile_photo_path),
            ] : null),
            'review' => $this->whenLoaded('review', fn () => $this->review
                ? new ClientReviewResource($this->review)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
