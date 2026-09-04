<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;

class AddDisputeNoteRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'regex:/\S/', 'max:2000']];
    }
}
