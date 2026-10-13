<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Modules\Support\Models\SupportTicketAttachment;
use App\Shared\Helpers\PrivateImages;
use App\Shared\Requests\BaseFormRequest;

class StoreClientSupportTicketRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:1', 'max:160'],
            'description' => ['required', 'string', 'min:1', 'max:10000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Optional screenshots or photos, sent as multipart images[].
            'images' => ['sometimes', 'array', 'max:'.SupportTicketAttachment::MAX_PER_POST],
            'images.*' => PrivateImages::RULES,
        ];
    }

    public function messages(): array
    {
        return PrivateImages::RULE_MESSAGES;
    }
}
