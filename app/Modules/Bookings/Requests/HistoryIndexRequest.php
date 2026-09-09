<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;

class HistoryIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
