<?php

namespace App\Modules\IdentityVerification\Resources;

use App\Shared\Resources\BaseResource;

/**
 * The signed-in account holder's own verification state.
 *
 * Exposes the last four digits of the card number and nothing more: never the
 * number, never the hash, never a storage path. The holder already knows their
 * own number, but this response travels through logs, caches and crash
 * reporters, so it carries the minimum that still lets the app show "…4821"
 * for confirmation.
 */
class IdentityVerificationResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            // unverified · pending · verified · rejected
            'status' => $this->status,
            'can_submit' => $this->canSubmit(),
            'id_number_last4' => $this->id_number_last4,
            'full_name' => $this->full_name,
            'birthdate' => $this->birthdate?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document): array => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'file_name' => $document->file_name,
                'file_mime_type' => $document->file_mime_type,
                'file_size' => $document->file_size,
                'created_at' => $document->created_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
