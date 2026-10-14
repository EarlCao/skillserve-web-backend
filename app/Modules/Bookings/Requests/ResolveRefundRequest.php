<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/** An administrator's decision on a refund the parties could not settle. */
class ResolveRefundRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::in(['returned', 'not_returned'])],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
