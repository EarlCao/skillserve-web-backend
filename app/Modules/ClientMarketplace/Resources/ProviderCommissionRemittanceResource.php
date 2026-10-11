<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Modules\Commissions\Models\CommissionRemittance;
use App\Shared\Resources\BaseResource;

/**
 * A GCash commission payment as the provider who made it sees it. The
 * reviewer's identity is left out; a rejection carries its reason so the
 * provider knows what to fix before submitting again.
 */
class ProviderCommissionRemittanceResource extends BaseResource
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
            'bookings_count' => $this->whenCounted('bookings'),
            'rejection_reason' => $this->status === CommissionRemittance::REJECTED ? $this->review_notes : null,
            'submitted_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
