<?php

namespace App\Modules\Providers\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Shapes a VerificationDocument into the standard API envelope data format.
 */
class VerificationDocumentResource extends BaseResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'verification_request_id' => $this->verification_request_id,
            'document_type' => $this->document_type,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'file_url' => $this->file_url,
            'file_mime_type' => $this->file_mime_type,
            'file_size' => $this->file_size,
            'formatted_file_size' => $this->formatted_file_size,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
