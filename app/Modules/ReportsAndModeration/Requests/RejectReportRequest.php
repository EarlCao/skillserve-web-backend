<?php

namespace App\Modules\ReportsAndModeration\Requests;

use App\Shared\Requests\BaseFormRequest;

class RejectReportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A rejection reason is required.',
        ];
    }
}
