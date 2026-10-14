<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Helpers\PrivateImages;
use App\Shared\Requests\BaseFormRequest;

/** The provider returned the customer's payment: proof is required. */
class MarkRefundSentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:100'],
            'image' => ['required', ...PrivateImages::RULES],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Attach a screenshot of the GCash transfer back to the customer.',
            'image.image' => 'The screenshot must be a photo.',
            'image.mimes' => 'The screenshot must be a JPG, PNG or WebP image.',
            'image.max' => 'The screenshot must be 5 MB or smaller.',
        ];
    }
}
