<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;

class ClientBookingResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'service_price' => $this->service_price,
            'total_price' => $this->total_price,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'cancellation_payment_policy' => $this->when($this->isCancelled(), fn () => $this->cancellationPaymentPolicy()),
            'client_notes' => $this->client_notes,
            'cancellation_reason' => $this->cancellation_reason,
            'scheduled_date' => $this->scheduled_date?->toIso8601String(),
            'scheduled_end_date' => $this->scheduled_end_date?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'is_reviewed' => $this->is_reviewed,
            'service' => $this->whenLoaded('service', fn () => new ClientServiceResource($this->service)),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'business_name' => $this->provider->business_name,
                'average_rating' => $this->provider->average_rating,
            ] : null),
            'review' => $this->whenLoaded('review', fn () => $this->review
                ? new ClientReviewResource($this->review)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
