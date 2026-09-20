<?php

namespace App\Modules\ClientAuthentication\Requests;

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
            'phone' => $this->has('phone') ? trim((string) $this->input('phone')) : null,
            'address' => $this->has('address') ? trim((string) $this->input('address')) : null,
        ], static fn ($value) => $value !== null));
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
