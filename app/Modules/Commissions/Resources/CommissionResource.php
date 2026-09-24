<?php

namespace App\Modules\Commissions\Resources;

use App\Shared\Resources\BaseResource;

/**
 * A booking's commission as the administrator's ledger shows it: what was
 * charged, whether SkillServe has been paid it, and how.
 *
 * Wraps a Booking — the commission lives on the booking it was charged for.
 */
class CommissionResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'booking_id' => $this->id,
            'booking_number' => $this->booking_number,
            'booking_status' => $this->status,

            'total_price' => $this->total_price,
            'commission_rate' => $this->commission_rate,
            'commission_amount' => $this->platform_fee,
            // What the provider keeps once SkillServe's share is taken out.
            'net_amount' => number_format(
                round((float) $this->total_price - (float) $this->platform_fee, 2),
                2, '.', '',
            ),
            'currency' => $this->currency,

            'commission_status' => $this->commission_status,
            'commission_settled_at' => $this->commission_settled_at?->toIso8601String(),

            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at?->toIso8601String(),

            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'business_name' => $this->provider->business_name,
            ] : null),
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'title' => $this->service->title,
            ] : null),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ] : null),

            'settlement' => $this->whenLoaded('commissionSettlement', fn () => $this->commissionSettlement ? [
                'amount' => $this->commissionSettlement->amount,
                'method' => $this->commissionSettlement->method,
                'reference' => $this->commissionSettlement->reference,
                'notes' => $this->commissionSettlement->notes,
                'settled_at' => $this->commissionSettlement->settled_at?->toIso8601String(),
                'settled_by' => $this->commissionSettlement->settledBy?->only(['id', 'name']),
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
