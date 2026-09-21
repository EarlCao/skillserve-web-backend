<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Modules\ReportsAndModeration\Models\Report;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreClientReportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        // Exactly one subject: the other party on a booking, a review, or a
        // message the caller received.
        $oneOf = fn (string $field): array => ['prohibits:'.implode(',', array_diff(['booking_id', 'review_id', 'message_id'], [$field]))];

        return [
            'booking_id' => ['required_without_all:review_id,message_id', 'nullable', 'integer', 'exists:bookings,id', ...$oneOf('booking_id')],
            'review_id' => ['nullable', 'integer', 'exists:reviews,id', ...$oneOf('review_id')],
            'message_id' => ['nullable', 'integer', 'exists:messages,id', ...$oneOf('message_id')],
            'reason' => ['required', 'string', Rule::in(Report::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => 'Please describe what happened in at least 10 characters.',
        ];
    }
}
