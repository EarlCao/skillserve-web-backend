<?php

namespace App\Modules\IdentityVerification\Requests;

use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class IdentityVerificationIndexRequest extends BaseFormRequest
{
    /** @var array<int, string> */
    public const STATUSES = [
        IdentityVerification::UNVERIFIED,
        IdentityVerification::PENDING,
        IdentityVerification::VERIFIED,
        IdentityVerification::REJECTED,
    ];

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
            // Searches the account's name or email — never the ID number,
            // which is not searchable by design.
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_type' => ['sometimes', 'nullable', Rule::in(['customer', 'provider'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['submitted_at', 'reviewed_at', 'created_at'])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
