<?php

namespace App\Modules\Providers\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Shapes a VerificationRequest into the standard API envelope data format.
 */
class VerificationRequestResource extends BaseResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'provider_profile_id' => $this->provider_profile_id,
            'status' => $this->status,
            'notes' => $this->notes,
            'admin_notes' => $this->admin_notes,
            'rejection_reason' => $this->rejection_reason,
            'additional_info_request' => $this->additional_info_request,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewedBy', function () {
                return [
                    'id' => $this->reviewedBy->id,
                    'name' => $this->reviewedBy->name,
                ];
            }),
            'documents' => VerificationDocumentResource::collection($this->whenLoaded('documents')),
            'documents_count' => $this->whenCounted('documents'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
