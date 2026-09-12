<?php

namespace App\Modules\ClientAuthentication\Requests;

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
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],

            // Provider profile fields (Step 1 of mobile onboarding).
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'specialization' => ['required', 'string', 'max:255'],
            'experience_years' => ['sometimes', 'integer', 'min:0', 'max:80'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
