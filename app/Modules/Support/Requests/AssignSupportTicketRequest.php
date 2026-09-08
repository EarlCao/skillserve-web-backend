<?php

namespace App\Modules\Support\Requests;

use App\Shared\Requests\BaseFormRequest;

class AssignSupportTicketRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
