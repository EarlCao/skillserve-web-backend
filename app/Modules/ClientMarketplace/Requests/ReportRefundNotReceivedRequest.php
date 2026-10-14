<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

/** The customer says the returned payment did not arrive, and why. */
class ReportRefundNotReceivedRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.min' => 'Please write at least 10 characters.',
        ];
    }
}
