<?php

namespace App\Modules\ClientCommunication\Requests;

use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\ReportsAndModeration\Models\ReportAttachment;
use App\Shared\Helpers\PrivateImages;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreClientReportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        // Exactly one subject: the other party on a booking, a review, a
        // message the caller received, a service listing, or a provider's
        // public profile.
        $subjects = ['booking_id', 'review_id', 'message_id', 'service_id', 'provider_id'];
        $oneOf = fn (string $field): array => ['prohibits:'.implode(',', array_diff($subjects, [$field]))];

        return [
            'booking_id' => ['required_without_all:review_id,message_id,service_id,provider_id', 'nullable', 'integer', 'exists:bookings,id', ...$oneOf('booking_id')],
            'review_id' => ['nullable', 'integer', 'exists:reviews,id', ...$oneOf('review_id')],
            'message_id' => ['nullable', 'integer', 'exists:messages,id', ...$oneOf('message_id')],
            'service_id' => ['nullable', 'integer', ...$oneOf('service_id')],
            'provider_id' => ['nullable', 'integer', ...$oneOf('provider_id')],
            'reason' => ['required', 'string', Rule::in(Report::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],

            // Optional photos as proof, sent as multipart images[].
            'images' => ['sometimes', 'array', 'max:'.ReportAttachment::MAX_PER_REPORT],
            'images.*' => PrivateImages::RULES,
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => 'Please describe what happened in at least 10 characters.',
            ...PrivateImages::RULE_MESSAGES,
        ];
    }
}
