<?php

namespace App\Shared\Requests;

/**
 * An administrator action against someone — deleting, hiding, removing a
 * verification or badge — that must say why. The reason is passed on to the
 * person affected (notification and, for deletions, email) and logged.
 */
class ActionReasonRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please give a reason. The person affected is told what you write.',
            'reason.min' => 'The reason must be at least 3 characters.',
            'reason.max' => 'The reason must not exceed 1000 characters.',
        ];
    }
}
