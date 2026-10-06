<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Modules\Locations\Services\PhAddressService;
use App\Shared\Helpers\AgeRequirement;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class RegisterProviderClientRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
            'email' => strtolower(trim((string) $this->input('email'))),
            'business_name' => trim((string) $this->input('business_name')),
            'specialization' => trim((string) $this->input('specialization')),
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            // Chosen after the emailed code is confirmed (POST /auth/complete-registration).
            // Still accepted here so older app versions keep working.
            'password' => ['sometimes', 'string', 'min:8', 'max:255', 'confirmed'],

            // Provider profile fields (Step 1 of mobile onboarding).
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'specialization' => ['required', 'string', 'max:255'],
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
