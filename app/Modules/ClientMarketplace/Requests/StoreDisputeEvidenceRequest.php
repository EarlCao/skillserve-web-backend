<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

class StoreDisputeEvidenceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Same limits as every other image a mobile account uploads.
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.max' => 'The image must be 5 MB or smaller.',
            'image.mimes' => 'The image must be a JPG, PNG or WebP file.',
        ];
    }
}
