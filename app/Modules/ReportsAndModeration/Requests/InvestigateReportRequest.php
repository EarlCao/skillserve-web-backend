<?php

namespace App\Modules\ReportsAndModeration\Requests;

use App\Shared\Requests\BaseFormRequest;

class InvestigateReportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
