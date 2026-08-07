<?php

namespace App\Shared\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base class for API form requests.
 *
 * Concrete requests should define rules() (and optionally
 * authorize()). Validation failures are automatically rendered as the
 * standard API error envelope by the global exception handler.
 */
abstract class BaseFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
