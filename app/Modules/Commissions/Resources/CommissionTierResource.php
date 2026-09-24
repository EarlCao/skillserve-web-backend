<?php

namespace App\Modules\Commissions\Resources;

use App\Shared\Resources\BaseResource;

/**
 * A configured commission band as the admin web reads it. Amounts stay
 * strings (the decimal cast) so the client never loses precision to a float.
 */
class CommissionTierResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_amount' => $this->min_amount,
            // null = open ended: everything above min_amount.
            'max_amount' => $this->max_amount,
            'is_open_ended' => $this->isOpenEnded(),
            'percentage' => $this->percentage,
            'is_active' => $this->is_active,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
