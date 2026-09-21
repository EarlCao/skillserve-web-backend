<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreClientReportRequest extends BaseFormRequest
{
    /**
     * Why someone is being reported. Stored as the key so the reason survives
     * copy changes in the app; the app owns the wording.
     *
     * @var array<int, string>
     */
    public const REASONS = [
        'service_quality',
        'no_show',
        'safety_concern',
        'payment_dispute',
        'misleading_information',
        'harassment',
        'inappropriate_content',
        'spam',
        'other',
    ];

    public function rules(): array
    {
        // Exactly one subject: the other party on a booking, a review, or a
        // message the caller received.
        $oneOf = fn (string $field): array => ['prohibits:'.implode(',', array_diff(['booking_id', 'review_id', 'message_id'], [$field]))];

        return [
            'booking_id' => ['required_without_all:review_id,message_id', 'nullable', 'integer', 'exists:bookings,id', ...$oneOf('booking_id')],
            'review_id' => ['nullable', 'integer', 'exists:reviews,id', ...$oneOf('review_id')],
            'message_id' => ['nullable', 'integer', 'exists:messages,id', ...$oneOf('message_id')],
            'reason' => ['required', 'string', Rule::in(self::REASONS)],
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
