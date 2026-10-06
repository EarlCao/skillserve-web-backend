<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Modules\Locations\Services\PhAddressService;
use App\Shared\Helpers\PhilippineMobileNumber;
use App\Shared\Requests\BaseFormRequest;

/**
 * The fields the mobile Edit Profile screen maintains. Email and role are
 * deliberately absent: changing an address re-opens verification and the
 * role decides authorization, so neither belongs in a profile edit.
 */
class UpdateClientProfileRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        // Trim only what was actually sent, so a partial update stays partial.
        $this->merge(array_filter([
            'first_name' => $this->has('first_name') ? trim((string) $this->input('first_name')) : null,
            'last_name' => $this->has('last_name') ? trim((string) $this->input('last_name')) : null,
            // +63 912 345 6789 and friends become 09123456789.
            'phone' => $this->has('phone') ? PhilippineMobileNumber::normalise($this->input('phone')) : null,
            'address' => $this->has('address') ? trim((string) $this->input('address')) : null,
        ], static fn ($value) => $value !== null));
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', ...PhilippineMobileNumber::rules()],
            // Free text from older app versions; the structured address below
            // replaces it when sent.
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            ...PhAddressService::rules('address_details', PhAddressService::STREET),
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => PhilippineMobileNumber::MESSAGE];
    }
}
