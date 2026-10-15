<?php

namespace App\Shared\Requests;

/**
 * An administrator action in someone's favour — reactivating, restoring,
 * featuring — with an optional note that is passed on to the person.
 */
class ActionNoteRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $note = trim((string) $this->input('reason'));
        $this->merge(['reason' => $note === '' ? null : $note]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.max' => 'The note must not exceed 1000 characters.',
        ];
    }
}
