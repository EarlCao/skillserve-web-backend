<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Helpers\PrivateImages;
use App\Shared\Requests\BaseFormRequest;

/** A customer's proof of a GCash payment: what they paid and a screenshot. */
class StorePaymentProofRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Checked against what is still owed in the service.
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999.99'],
            'reference' => ['nullable', 'string', 'max:100'],
            'image' => ['required', ...PrivateImages::RULES],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Attach a screenshot of your GCash payment.',
            'image.image' => 'The screenshot must be a photo.',
            'image.mimes' => 'The screenshot must be a JPG, PNG or WebP image.',
            'image.max' => 'The screenshot must be 5 MB or smaller.',
        ];
    }
}
