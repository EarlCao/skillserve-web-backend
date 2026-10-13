<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Modules\ClientMarketplace\Services\ProviderStatisticsService;
use App\Shared\Requests\BaseFormRequest;

class ProviderStatisticsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // How many calendar months the chart covers, ending with this one.
            'months' => ['sometimes', 'integer', 'min:1', 'max:'.ProviderStatisticsService::MAX_MONTHS],
        ];
    }
}
