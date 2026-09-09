<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;

class ClientSubcategoryResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
