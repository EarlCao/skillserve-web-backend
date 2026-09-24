<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Modules\Bookings\Enums\PaymentMethod;
use App\Shared\Helpers\BusinessTime;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreClientBookingRequest extends BaseFormRequest
{
    /**
     * @var array<int, string>
     *
     * @deprecated Use PaymentMethod::accepted(). Kept because the Swagger
     * attributes and the api-docs generator reference this constant.
     */
    public const PAYMENT_METHODS = ['on_hand', 'gcash', 'cash'];

    protected function prepareForValidation(): void
    {
        $merge = ['idempotency_key' => $this->header('Idempotency-Key')];

        // Canonicalise before validation so the deprecated "cash" alias the
        // current mobile build sends is stored as "on_hand".
        if ($this->has('payment_method')) {
            $method = PaymentMethod::fromInput($this->input('payment_method'));
            $merge['payment_method'] = $method?->value ?? $this->input('payment_method');
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'scheduled_date' => ['required', 'date', 'after:now'],
            // When omitted, the service duration determines the end time.
            'scheduled_end_date' => ['sometimes', 'nullable', 'date', 'after:scheduled_date'],
            'client_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'service_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'payment_method' => ['sometimes', 'nullable', 'string', Rule::in(PaymentMethod::canonical())],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = trim((string) $this->header('Idempotency-Key'));

        return $key === '' ? null : $key;
    }

    /**
     * Store UTC: a time with an offset keeps its instant; one without is read
     * as business-time wall clock (see BusinessTime).
     */
    protected function passedValidation(): void
    {
        $data = $this->validator->getData();
        foreach (['scheduled_date', 'scheduled_end_date'] as $field) {
            if (filled($data[$field] ?? null)) {
                $data[$field] = BusinessTime::toUtc((string) $data[$field])->toIso8601String();
            }
        }
        // validated() reads the validator's data, not the request's.
        $this->validator->setData($data);
    }
}
