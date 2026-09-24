<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Modules\Commissions\Services\CommissionCalculator;

/**
 * A provider's own service, including its moderation state and what the
 * provider would actually earn from it.
 */
class ProviderServiceResource extends ClientServiceResource
{
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'category_id' => $this->category_id,
            'subcategory_id' => $this->subcategory_id,
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'rejection_reason' => $this->rejection_reason,
            'is_featured' => (bool) $this->is_featured,
            'is_hidden' => (bool) $this->is_hidden,
            'approved_at' => $this->approved_at?->toIso8601String(),
            // What this price means for the provider today. Indicative only:
            // the binding rate is snapshotted onto each booking when it is
            // made. The calculator memoises the tier lookup, so listing many
            // services stays a single query.
            'earnings' => $this->earnings(),
        ]);
    }

    /**
     * @return array{price: string, commission_rate: string, commission_amount: string, net_amount: string}
     */
    private function earnings(): array
    {
        $breakdown = app(CommissionCalculator::class)->for((float) $this->price);

        return [
            'price' => $this->money($breakdown['base_amount']),
            'commission_rate' => $this->money($breakdown['rate']),
            'commission_amount' => $this->money($breakdown['commission_amount']),
            'net_amount' => $this->money($breakdown['net_amount']),
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
