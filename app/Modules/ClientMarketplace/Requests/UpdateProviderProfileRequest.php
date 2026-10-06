<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Helpers\AgeRequirement;
use App\Shared\Helpers\PhilippineMobileNumber;
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
        // Trim what was sent, but keep an explicit null as null: a nullable
        // column cleared by the client should end up empty, not holding an
        // empty string that `filled()` and `nullable` rules then disagree
        // about.
        foreach (['business_name', 'specialization', 'location', 'website', 'gcash_name'] as $field) {
            if ($this->has($field)) {
                $trimmed = trim((string) $this->input($field));
                $this->merge([$field => $trimmed === '' ? null : $trimmed]);
            }
        }

        // Store one shape whatever the provider types: +63/63/9xxxxxxxxx all
        // become 09XXXXXXXXX, so the number the customer is shown always looks
        // like the one they will type into GCash.
        if ($this->has('gcash_number')) {
            $this->merge(['gcash_number' => PhilippineMobileNumber::normalise($this->input('gcash_number'))]);
        }
    }

    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'specialization' => ['sometimes', 'required', 'string', 'max:255'],
            // Counted from age 16 at the earliest, from the birthday on the account.
            'experience_years' => ['sometimes', ...AgeRequirement::experienceRules($this->user()?->birthday)],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],

            // Where customers send payment. Personal payment details, shown
            // only to a customer who has a booking with this provider.
            'gcash_number' => ['sometimes', 'nullable', ...PhilippineMobileNumber::rules()],
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
            ...AgeRequirement::messages(),
        ];
    }
}
