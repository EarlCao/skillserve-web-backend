<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Shared\Requests\BaseFormRequest;

class BackgroundNotificationIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'after' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
