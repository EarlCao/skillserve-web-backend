<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;

/**
 * The signed-in provider's verification state: the profile status plus the
 * latest request and its documents. Never exposes storage paths.
 *
 * Wraps ['profile' => ProviderProfile, 'request' => ?VerificationRequest].
 */
class ProviderVerificationResource extends BaseResource
{
    public function toArray($request): array
    {
        $profile = $this->resource['profile'];
        $verification = $this->resource['request'];

        return [
            // unverified · pending · verified · rejected · additional_info_required
            'verification_status' => $profile->verification_status,
            'verified_at' => $profile->verified_at?->toIso8601String(),
            'can_submit' => in_array($profile->verification_status, ['unverified', 'rejected', 'additional_info_required'], true),
            'request' => $verification ? [
                'id' => $verification->id,
                'status' => $verification->status,
                'notes' => $verification->notes,
                'rejection_reason' => $verification->rejection_reason,
                'additional_info_request' => $verification->additional_info_request,
                'admin_notes' => $verification->status === 'approved' ? $verification->admin_notes : null,
                'submitted_at' => $verification->submitted_at?->toIso8601String(),
                'reviewed_at' => $verification->reviewed_at?->toIso8601String(),
                'documents' => $verification->documents->map(fn ($document): array => [
                    'id' => $document->id,
                    'document_type' => $document->document_type,
                    'file_name' => $document->file_name,
                    'file_mime_type' => $document->file_mime_type,
                    'file_size' => $document->file_size,
                    'created_at' => $document->created_at?->toIso8601String(),
                ])->values()->all(),
            ] : null,
        ];
    }
}
