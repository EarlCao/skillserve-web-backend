<?php

namespace App\Modules\Users\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to ban a user account — either for a fixed number of
 * days (auto-lifted when the timer expires) or permanently ("forever").
 */
class BanUserRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'duration' => ['required', 'string', 'in:days,forever'],
            'days' => ['required_if:duration,days', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please provide a reason for the ban.',
            'reason.max' => 'The ban reason must not exceed 500 characters.',
            'duration.required' => 'Please choose how long the ban should last.',
            'duration.in' => 'The ban duration must be either days or forever.',
            'days.required_if' => 'Please specify how many days the ban should last.',
            'days.integer' => 'The ban duration must be a whole number of days.',
            'days.min' => 'A temporary ban must last at least 1 day.',
            'days.max' => 'A temporary ban cannot exceed 3650 days.',
        ];
    }
}
