<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class SubmitProviderVerificationRequest extends BaseFormRequest
{
    /** @var array<int, string> */
    public const DOCUMENT_TYPES = ['government_id', 'certificate', 'other'];

    public function rules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1', 'max:5'],
            'documents.*.type' => ['required', 'string', Rule::in(self::DOCUMENT_TYPES)],
            'documents.*.file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'documents.required' => 'Attach at least one document.',
            'documents.max' => 'Attach at most 5 documents at a time.',
            'documents.*.file.mimes' => 'Each document must be a JPG, PNG or PDF file.',
            'documents.*.file.max' => 'Each document must be 10 MB or smaller.',
        ];
    }
}
