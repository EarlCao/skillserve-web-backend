<?php

namespace App\Modules\Analytics\Requests;

use App\Shared\Requests\BaseFormRequest;

class GeneralReportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
