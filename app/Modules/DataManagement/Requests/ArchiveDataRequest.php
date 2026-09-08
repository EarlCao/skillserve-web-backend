<?php

namespace App\Modules\DataManagement\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ArchiveDataRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'resource_type' => ['required', Rule::in(config('data-management.archive_types'))],
            'resource_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
