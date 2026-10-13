<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Modules\Providers\Models\ProviderPortfolioItem;
use App\Shared\Requests\BaseFormRequest;

/**
 * A new piece of work: `images[]` (1 to ProviderPortfolioItem::MAX_IMAGES
 * photos, the first is the cover), or a single `image` from app versions
 * that predate albums.
 */
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
        // Same limits as the profile photo, so one storage policy covers
        // every image a mobile account uploads.
        $photo = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'images' => ['required_without:image', 'array', 'min:1', 'max:'.ProviderPortfolioItem::MAX_IMAGES],
            'images.*' => ['required', ...$photo],
            'image' => ['required_without:images', 'prohibits:images', ...$photo],
        ];
    }

    /** The uploaded photos, in order, whichever field carried them. */
    public function photos(): array
    {
        return $this->hasFile('images') ? array_values($this->file('images')) : [$this->file('image')];
    }

    public function messages(): array
    {
        return [
            'images.max' => 'A portfolio item can hold up to '.ProviderPortfolioItem::MAX_IMAGES.' photos.',
            'image.max' => 'The image must be 5 MB or smaller.',
            'images.*.max' => 'Each photo must be 5 MB or smaller.',
            'image.mimes' => 'The image must be a JPG, PNG or WebP file.',
            'images.*.mimes' => 'Each photo must be a JPG, PNG or WebP file.',
        ];
    }
}
