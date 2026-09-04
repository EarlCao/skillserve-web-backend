<?php

namespace App\Modules\ReportsAndModeration\Requests;

use App\Shared\Requests\BaseFormRequest;

class AddInvestigationNoteRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'An investigation note is required.',
        ];
    }
}
