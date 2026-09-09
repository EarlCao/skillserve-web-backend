<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Shared\Requests\BaseFormRequest;

class StoreClientSupportTicketRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:1', 'max:160'],
            'description' => ['required', 'string', 'min:1', 'max:10000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
