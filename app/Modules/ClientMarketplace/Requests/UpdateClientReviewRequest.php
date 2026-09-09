<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

class UpdateClientReviewRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
