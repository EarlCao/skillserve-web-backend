<?php

namespace App\Modules\ServiceCategories\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an enable/disable request for a service category.
 */
class SetServiceCategoryStatusRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['enabled', 'disabled'])],
        ];
    }
}
