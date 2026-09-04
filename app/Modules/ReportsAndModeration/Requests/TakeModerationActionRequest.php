<?php

namespace App\Modules\ReportsAndModeration\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class TakeModerationActionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['warning', 'suspend', 'ban', 'hide', 'remove'])],
            'reason' => ['required_if:action,suspend', 'required_if:action,ban', 'nullable', 'string', 'max:500'],
            'duration' => ['required_if:action,ban', 'nullable', 'string', Rule::in(['days', 'forever'])],
            'days' => ['required_if:duration,days', 'nullable', 'integer', 'min:1', 'max:3650'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'A moderation action is required.',
            'reason.required_if' => 'A reason is required for this action.',
            'duration.required_if' => 'A ban duration is required.',
            'days.required_if' => 'A number of days is required for a temporary ban.',
        ];
    }
}
