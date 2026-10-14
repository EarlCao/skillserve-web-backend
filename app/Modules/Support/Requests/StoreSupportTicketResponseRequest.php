<?php

namespace App\Modules\Support\Requests;

use App\Modules\Support\Models\SupportTicketAttachment;
use App\Shared\Helpers\PrivateImages;
use App\Shared\Requests\BaseFormRequest;

/**
 * A staff reply in the Customer Support chat: text, photos, or both.
 */
class StoreSupportTicketResponseRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required_without:images', 'nullable', 'string', 'max:5000'],
            'images' => ['sometimes', 'array', 'max:'.SupportTicketAttachment::MAX_PER_POST],
            'images.*' => PrivateImages::RULES,
        ];
    }

    public function messages(): array
    {
        return [
            'body.required_without' => 'Write a reply or attach a photo.',
            ...PrivateImages::RULE_MESSAGES,
        ];
    }
}
