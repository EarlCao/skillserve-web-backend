<?php

namespace App\Modules\Notifications\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:10000'],
            'target' => ['required', Rule::in(['all', 'customers', 'providers', 'selected'])],
            'recipient_ids' => ['required_if:target,selected', 'array', 'min:1', 'max:1000'],
            'recipient_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : $this->title,
            'message' => is_string($this->message) ? trim($this->message) : $this->message,
        ]);
    }

    public function messages(): array
    {
        return [
            'recipient_ids.required_if' => 'Select at least one recipient.',
            'scheduled_at.after' => 'The scheduled time must be in the future.',
        ];
    }
}
