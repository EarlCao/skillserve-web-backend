<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Modules\Locations\Services\PhAddressService;
use App\Shared\Helpers\AgeRequirement;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Details collected on the "complete your profile" screen after a Google
 * account with no SkillServe account signs in. The ID token is re-verified
 * server-side, so the email is never taken from the request body.
 */
class CompleteGoogleRegistrationRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
            'role' => strtolower(trim((string) $this->input('role', 'customer'))),
            'business_name' => trim((string) $this->input('business_name')),
            'specialization' => trim((string) $this->input('specialization')),
        ]);
    }

    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'min:20'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['customer', 'provider'])],

            // Provider profile fields, required only for provider sign-ups.
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'specialization' => ['required_if:role,provider', 'nullable', 'string', 'max:255'],
            // Counted from age 16 at the earliest: 2 years at 18, 3 at 19...
            'experience_years' => ['sometimes', ...AgeRequirement::experienceRules($this->input('birthday'))],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Read from the National ID at sign-up. SkillServe is for adults
            // only, so it is required and must be 18 or more years ago.
            'birthday' => AgeRequirement::birthdayRules(),
            ...PhAddressService::rules('address_details', PhAddressService::STREET),
        ];
    }

    public function messages(): array
    {
        return AgeRequirement::messages();
    }
}
