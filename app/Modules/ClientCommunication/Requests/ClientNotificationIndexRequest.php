<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Shared\Requests\BaseFormRequest;

class ClientNotificationIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
