<?php

namespace App\Modules\Reviews\Requests;

use App\Shared\Requests\BaseFormRequest;

class HideReviewRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'is_hidden' => ['required', 'boolean'],
            // Hiding says why — the reviewer is told; restoring may carry a note.
            'reason' => ['required_if_accepted:is_hidden', 'nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
