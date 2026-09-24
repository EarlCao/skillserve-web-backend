<?php

namespace App\Modules\IdentityVerification\Requests;

use App\Shared\Requests\BaseFormRequest;

class ApproveIdentityVerificationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Optional internal note for the audit trail; never shown to the
            // account holder.
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
