<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * The amount a provider is considering charging, so the app can show what
 * they would actually receive before they publish the price.
 */
class CommissionPreviewRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    public function attributes(): array
    {
        return ['amount' => 'price'];
    }
}
