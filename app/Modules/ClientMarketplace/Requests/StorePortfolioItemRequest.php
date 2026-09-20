<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

class StorePortfolioItemRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'description' => trim((string) $this->input('description')),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Same limits as the profile photo, so one storage policy covers
            // every image a mobile account uploads.
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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
