<?php

namespace App\Modules\IdentityVerification\Requests;

use App\Modules\IdentityVerification\Models\IdentityDocument;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A National ID submission. The number is validated for shape here; whether it
 * is already in use is decided by the service inside a locked transaction,
 * because that is a cross-row rule.
 */
class SubmitIdentityVerificationRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        // The app may send the number grouped; compare digits only.
        $this->merge([
            'id_number' => preg_replace('/\D/', '', (string) $this->input('id_number')) ?? '',
        ]);
    }

    public function rules(): array
    {
        $digits = (int) config('identity.card_number_digits', 16);

        return [
            'id_number' => ['required', 'string', 'digits:'.$digits],
            'full_name' => ['required', 'string', 'max:255'],
            'birthdate' => ['required', 'date', 'before:today'],

            // Front and back of the card, plus a selfie for the reviewer to
            // match against it.
            'documents' => ['required', 'array', 'min:1', 'max:3'],
            'documents.*.type' => ['required', 'string', Rule::in(IdentityDocument::TYPES)],
            'documents.*.file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_number.digits' => 'Enter the 16 digits printed on your PhilSys card.',
            'documents.required' => 'Attach a photo of your National ID.',
            'documents.*.file.mimes' => 'Each file must be a JPG, PNG or PDF.',
            'documents.*.file.max' => 'Each file must be 10 MB or smaller.',
        ];
    }

    public function attributes(): array
    {
        return ['id_number' => 'National ID number'];
    }
}
