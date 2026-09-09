<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ClientSupportTicketIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(['open', 'in_progress', 'resolved'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
