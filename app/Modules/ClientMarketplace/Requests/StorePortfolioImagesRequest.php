<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Modules\Providers\Models\ProviderPortfolioItem;
use App\Shared\Requests\BaseFormRequest;

class StorePortfolioImagesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.ProviderPortfolioItem::MAX_IMAGES],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.*.max' => 'Each photo must be 5 MB or smaller.',
            'images.*.mimes' => 'Each photo must be a JPG, PNG or WebP file.',
        ];
    }
}
