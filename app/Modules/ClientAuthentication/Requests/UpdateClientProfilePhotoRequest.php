<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class UpdateClientProfilePhotoRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Restricted to real images, and small enough that a phone photo
            // still uploads over mobile data.
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.max' => 'The photo must be 5 MB or smaller.',
            'photo.mimes' => 'The photo must be a JPG, PNG or WebP image.',
        ];
    }
}
