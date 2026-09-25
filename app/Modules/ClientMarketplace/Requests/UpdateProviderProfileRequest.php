<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * A provider editing their own professional profile.
 *
 * Partial by design — the onboarding screen saves a few fields at a time —
 * so every rule is `sometimes` and only what is sent changes. Verification
 * status, featured flag and rating counters are not accepted at all; those
 * are set by administrators or earned.
 */
class UpdateProviderProfileRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['business_name', 'specialization', 'location', 'website', 'gcash_name'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }

        // Store one shape whatever the provider types: +63/63/9xxxxxxxxx all
        // become 09XXXXXXXXX, so the number the customer is shown always looks
        // like the one they will type into GCash.
        if ($this->has('gcash_number')) {
            $digits = preg_replace('/\D/', '', (string) $this->input('gcash_number')) ?? '';
            $normalised = match (true) {
                str_starts_with($digits, '639') && strlen($digits) === 12 => '0'.substr($digits, 2),
                str_starts_with($digits, '9') && strlen($digits) === 10 => '0'.$digits,
                default => $digits,
            };
            $this->merge(['gcash_number' => $normalised === '' ? null : $normalised]);
        }
    }

    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'specialization' => ['sometimes', 'required', 'string', 'max:255'],
            'experience_years' => ['sometimes', 'integer', 'min:0', 'max:80'],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],

            // Where customers send payment. Personal payment details, shown
            // only to a customer who has a booking with this provider.
            'gcash_number' => ['sometimes', 'nullable', 'string', 'regex:/^09\d{9}$/'],
            'gcash_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'skills' => ['sometimes', 'nullable', 'array', 'max:50'],
            'skills.*' => ['string', 'max:100'],
            'certifications' => ['sometimes', 'nullable', 'array', 'max:50'],
            'certifications.*' => ['string', 'max:255'],
            'languages' => ['sometimes', 'nullable', 'array', 'max:20'],
            'languages.*' => ['string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'gcash_number.regex' => 'Enter an 11-digit GCash number starting with 09.',
        ];
    }
}
