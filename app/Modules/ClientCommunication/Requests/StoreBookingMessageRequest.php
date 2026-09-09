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
            'content' => ['required', 'string', 'min:1', 'max:5000'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = trim((string) $this->header('Idempotency-Key'));

        return $key === '' ? null : $key;
    }
}
