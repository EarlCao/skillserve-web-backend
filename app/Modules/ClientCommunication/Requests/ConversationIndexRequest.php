<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Shared\Requests\BaseFormRequest;

class ConversationIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            // Conversations before a booking too; off for app builds that
            // only know booking threads.
            'include_inquiries' => ['sometimes', 'boolean'],
        ];
    }
}
