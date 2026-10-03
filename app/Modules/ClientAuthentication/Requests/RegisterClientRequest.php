<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Modules\Locations\Services\PhAddressService;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class RegisterClientRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
            'email' => strtolower(trim((string) $this->input('email'))),
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
            // Read from the National ID at sign-up; optional so older app
            // versions, which do not send them, keep working.
            'birthday' => ['sometimes', 'nullable', 'date', 'before:today', 'after:1900-01-01'],
            ...PhAddressService::rules('address_details', PhAddressService::STREET),
        ];
    }
}
