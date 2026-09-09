<?php

namespace App\Modules\Bookings\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

class BookingResource extends BaseResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'total_price' => $this->total_price,
            'service_price' => $this->service_price,
            'platform_fee' => $this->platform_fee,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'cancellation_payment_policy' => $this->when($this->isCancelled(), fn () => $this->cancellationPaymentPolicy()),
            'client_notes' => $this->client_notes,
            'provider_notes' => $this->provider_notes,
            'cancellation_reason' => $this->cancellation_reason,
            'scheduled_date' => $this->scheduled_date?->toIso8601String(),
            'scheduled_end_date' => $this->scheduled_end_date?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'dispute_reason' => $this->dispute_reason,
            'disputed_at' => $this->disputed_at?->toIso8601String(),
            'dispute_status' => $this->dispute_status,
            'dispute_resolution' => $this->dispute_resolution,
            'dispute_evidence' => $this->dispute_evidence ?? [],
            'dispute_notes' => $this->dispute_notes ?? [],
            'dispute_closed_at' => $this->dispute_closed_at?->toIso8601String(),
            'dispute_closed_by' => $this->whenLoaded('disputeClosedBy', fn () => $this->disputeClosedBy ? [
                'id' => $this->disputeClosedBy->id,
                'name' => $this->disputeClosedBy->name,
            ] : null),
            'is_reviewed' => $this->is_reviewed,
            'service' => $this->whenLoaded('service', fn () => [
                'id' => $this->service->id,
                'title' => $this->service->title,
                'price' => $this->service->price,
                'price_type' => $this->service->price_type,
                'currency' => $this->service->currency,
            ]),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $this->client->email,
            ]),
            'provider' => $this->whenLoaded('provider', fn () => [
                'id' => $this->provider->id,
                'business_name' => $this->provider->business_name,
                'user' => $this->provider->user ? [
                    'id' => $this->provider->user->id,
                    'name' => $this->provider->user->name,
                    'email' => $this->provider->user->email,
                ] : null,
            ]),
            'cancelled_by_user' => $this->whenLoaded('cancelledByUser', fn () => $this->cancelledByUser ? [
                'id' => $this->cancelledByUser->id,
                'name' => $this->cancelledByUser->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
