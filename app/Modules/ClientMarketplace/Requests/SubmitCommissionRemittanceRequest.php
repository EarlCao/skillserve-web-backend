<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * A provider's GCash commission payment. Whether it covers what they owe, and
 * whether the reference is already taken, are decided by the service under a
 * lock, because both depend on other rows.
 */
class SubmitCommissionRemittanceRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        // GCash prints the reference grouped ("1234 567 891234"); compare it
        // without the spacing, in one case.
        if ($this->has('reference')) {
            $this->merge([
                'reference' => strtoupper(preg_replace('/[\s\-]/', '', (string) $this->input('reference')) ?? ''),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'regex:/^[A-Z0-9]{6,40}$/'],
            'amount_sent' => ['required', 'numeric', 'min:1', 'max:9999999'],
            // Same limits as every other image a mobile account uploads.
            'proof' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference.required' => 'Enter the reference number from your GCash receipt.',
            'reference.regex' => 'Enter the reference number exactly as it appears on your GCash receipt.',
            'amount_sent.required' => 'Enter the amount you sent.',
            'proof.max' => 'The receipt image must be 5 MB or smaller.',
            'proof.mimes' => 'The receipt must be a JPG, PNG or WebP image.',
        ];
    }

    public function attributes(): array
    {
        return ['reference' => 'reference number', 'amount_sent' => 'amount sent'];
    }
}
