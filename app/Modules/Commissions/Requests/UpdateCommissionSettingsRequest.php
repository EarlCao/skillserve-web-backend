<?php

namespace App\Modules\Commissions\Requests;

use App\Shared\Requests\BaseFormRequest;

/** The commission rules edited on the Commission page; rules from the settings catalog. */
class UpdateCommissionSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return collect(config('system-settings.groups.marketplace'))
            ->filter(fn (array $definition): bool => ($definition['managed_by'] ?? null) === 'commissions')
            ->map(fn (array $definition): array => ['sometimes', ...$definition['rules']])
            ->all();
    }
}
