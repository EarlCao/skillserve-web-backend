<?php

namespace App\Modules\Support\Requests;

use App\Shared\Requests\BaseFormRequest;

class StoreSupportTicketResponseRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }
}
