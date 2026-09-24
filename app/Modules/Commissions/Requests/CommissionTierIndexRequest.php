<?php

namespace App\Modules\Commissions\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class CommissionTierIndexRequest extends BaseFormRequest
{
    /** @var array<int, string> */
    public const SORTABLE = ['min_amount', 'percentage', 'name', 'created_at'];

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'sort' => ['sometimes', 'nullable', Rule::in(self::SORTABLE)],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
