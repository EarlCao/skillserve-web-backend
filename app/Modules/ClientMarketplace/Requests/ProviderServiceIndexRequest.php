<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ProviderServiceIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'approval_status' => ['sometimes', 'string', Rule::in(['pending', 'approved', 'rejected'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
