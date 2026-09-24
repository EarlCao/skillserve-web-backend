<?php

namespace App\Modules\IdentityVerification\Requests;

use App\Shared\Requests\BaseFormRequest;

class RejectIdentityVerificationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // The holder is told why, so they can fix it and resubmit.
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
