<?php

namespace App\Modules\ClientAuthentication\Requests;

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
            'experience_years' => ['sometimes', 'integer', 'min:0', 'max:80'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
