<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Modules\Providers\Models\ProviderAvailability;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Validator;

/**
 * A provider publishing their weekly hours and whether they are taking new
 * bookings.
 *
 * `availability` is the whole schedule: sending it replaces every window,
 * and sending an empty array clears it (which means "no published hours",
 * not "never available"). Both fields are optional so the screen can save
 * the toggle alone.
 */
class UpdateProviderAvailabilityRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'is_accepting_bookings' => ['sometimes', 'boolean'],
            'availability' => ['sometimes', 'array', 'max:7'],
            'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'availability.*.start_time' => ['required', 'date_format:H:i'],
            'availability.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * Ordering and one-window-per-day are checked here rather than through
     * `after:`, which cannot resolve a sibling inside a wildcard array, and
     * so that a duplicate day fails validation instead of the unique index.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $days = [];

            foreach ((array) $this->input('availability', []) as $index => $window) {
                if (! is_array($window)) {
                    continue;
                }

                $start = ProviderAvailability::minutes($window['start_time'] ?? null);
                $end = ProviderAvailability::minutes($window['end_time'] ?? null);

                if ($start !== null && $end !== null && $end <= $start) {
                    $validator->errors()->add(
                        "availability.{$index}.end_time",
                        'The end time must be later than the start time.',
                    );
                }

                $day = $window['day_of_week'] ?? null;
                if ($day === null) {
                    continue;
                }

                if (in_array((int) $day, $days, true)) {
                    $validator->errors()->add(
                        "availability.{$index}.day_of_week",
                        'Each day may only appear once in the schedule.',
                    );
                }

                $days[] = (int) $day;
            }
        });
    }
}
