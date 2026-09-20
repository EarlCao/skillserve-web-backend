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
        foreach (['business_name', 'specialization', 'location', 'website'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
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
            'skills' => ['sometimes', 'nullable', 'array', 'max:50'],
            'skills.*' => ['string', 'max:100'],
            'certifications' => ['sometimes', 'nullable', 'array', 'max:50'],
            'certifications.*' => ['string', 'max:255'],
            'languages' => ['sometimes', 'nullable', 'array', 'max:20'],
            'languages.*' => ['string', 'max:100'],
        ];
    }
}
