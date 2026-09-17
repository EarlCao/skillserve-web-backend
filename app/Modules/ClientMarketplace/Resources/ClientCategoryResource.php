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
            'provider_count' => $this->when(
                array_key_exists('provider_count', $this->resource->getAttributes()),
                fn () => (int) $this->provider_count,
            ),
            'subcategories' => $this->whenLoaded(
                'subcategories',
                fn () => ClientSubcategoryResource::collection($this->subcategories),
            ),
        ];
    }
}
