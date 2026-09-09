<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreClientBookingRequest extends BaseFormRequest
{
    /** @var array<int, string> */
    public const PAYMENT_METHODS = [
        'cash', 'credit_card', 'debit_card', 'bank_transfer', 'gcash', 'paypal',
    ];

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'scheduled_date' => ['required', 'date', 'after:now'],
            // When omitted, the service duration determines the end time.
            'scheduled_end_date' => ['sometimes', 'nullable', 'date', 'after:scheduled_date'],
            'client_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'payment_method' => ['sometimes', 'nullable', 'string', Rule::in(self::PAYMENT_METHODS)],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = trim((string) $this->header('Idempotency-Key'));

        return $key === '' ? null : $key;
    }
}
