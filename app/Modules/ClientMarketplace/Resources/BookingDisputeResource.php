<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Modules\ClientMarketplace\Services\BookingDisputeService;
use App\Shared\Resources\BaseResource;

/**
 * A booking dispute as its two parties may see it.
 *
 * Carries the case status and, once there is one, the resolution — but never
 * `dispute_notes`, which is the administrators' internal working record.
 */
class BookingDisputeResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'booking_id' => $this->id,
            'booking_number' => $this->booking_number,
            'booking_status' => $this->status,
            'service_title' => $this->service?->title,
            'provider_name' => $this->provider?->business_name,
            'reason' => $this->dispute_reason,
            'dispute_status' => $this->dispute_status,
            'resolution' => $this->dispute_resolution,
            'disputed_at' => $this->disputed_at?->toIso8601String(),
            'closed_at' => $this->dispute_closed_at?->toIso8601String(),
            // What each side has attached. The file itself stays private: the
            // parties see that it was received, administrators open it.
            'evidence' => collect($this->dispute_evidence ?? [])
                ->map(fn (array $item): array => [
                    'id' => $item['id'] ?? null,
                    'label' => $item['label'] ?? 'Photo',
                    'uploaded_by_role' => $item['uploaded_by_role'] ?? null,
                    'is_mine' => (int) ($item['uploaded_by'] ?? 0) === (int) $request->user()?->id,
                    'uploaded_at' => $item['uploaded_at'] ?? null,
                ])
                ->values()
                ->all(),
            'can_add_evidence' => $this->dispute_reason !== null
                && in_array($this->dispute_status, ['pending', 'investigated'], true)
                && count($this->dispute_evidence ?? []) < BookingDisputeService::MAX_EVIDENCE,
        ];
    }
}
