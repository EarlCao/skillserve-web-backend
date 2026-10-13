<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Shared\Requests\BaseFormRequest;

class StoreBookingMessageRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            // Text, a photo, or both.
            'content' => ['required_without:image', 'nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = trim((string) $this->header('Idempotency-Key'));

        return $key === '' ? null : $key;
    }

    public function messages(): array
    {
        return [
            'content.required_without' => 'Write a message or attach a photo.',
            'image.max' => 'The photo must be 5 MB or smaller.',
            'image.mimes' => 'The photo must be a JPG, PNG or WebP file.',
        ];
    }
}
