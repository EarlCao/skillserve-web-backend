<?php

namespace App\Modules\DataManagement\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ExportDataRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(config('data-management.export_types'))],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
