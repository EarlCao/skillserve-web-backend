<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Shared\Requests\BaseFormRequest;

/** A customer starting (or reopening) a conversation with a provider. */
class StoreInquiryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // The provider's public profile id, as the marketplace shows it.
            'provider_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
