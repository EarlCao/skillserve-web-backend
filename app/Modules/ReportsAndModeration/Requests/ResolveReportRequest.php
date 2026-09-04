<?php

namespace App\Modules\ReportsAndModeration\Requests;

use App\Shared\Requests\BaseFormRequest;

class ResolveReportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution_note.required' => 'A resolution note is required.',
        ];
    }
}
