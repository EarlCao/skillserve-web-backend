<?php

namespace App\Modules\Commissions\Resources;

use App\Shared\Resources\BaseResource;

/**
 * A provider's GCash commission payment as the administrator reviews it:
 * what was owed, what the provider says they sent, the reference to look for
 * in SkillServe's GCash history, and the bookings a confirmation settles.
 *
 * The receipt is never linked directly; `has_proof` says whether one can be
 * downloaded through GET /api/commission-remittances/{id}/proof.
 */
class CommissionRemittanceResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reference' => $this->reference,
            'amount_due' => $this->amount_due,
            'amount_sent' => $this->amount_sent,
            'currency' => 'PHP',
            'has_proof' => $this->proof_path !== null,
            // Other providers' pending or confirmed claims on this reference;
            // more than 0 means check the sender before confirming.
            'other_claims_count' => (int) ($this->other_claims_count ?? 0),

            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'business_name' => $this->provider->business_name,
                'name' => $this->provider->user?->name,
                'email' => $this->provider->user?->email,
                // The account the money should have come from, to match
                // against the sender in SkillServe's GCash history.
                'gcash_name' => $this->provider->gcash_name,
                'gcash_number' => $this->provider->gcash_number,
            ] : null),

            'bookings' => $this->whenLoaded('bookings', fn () => $this->bookings->map(fn ($booking): array => [
                'id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'service' => $booking->service?->title,
                // Pivot columns are not cast, so it is formatted like the rest.
                'commission_amount' => number_format((float) $booking->pivot->amount, 2, '.', ''),
                'commission_status' => $booking->commission_status,
            ])->values()->all()),

            'review_notes' => $this->review_notes,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->only(['id', 'name'])),

            'submitted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
