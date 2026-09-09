<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;

class ClientCategoryResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'subcategories' => $this->whenLoaded(
                'subcategories',
                fn () => ClientSubcategoryResource::collection($this->subcategories),
            ),
        ];
    }
}
