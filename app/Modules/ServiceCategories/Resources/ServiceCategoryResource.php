<?php

namespace App\Modules\ServiceCategories\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Shapes a service category for the management screens.
 *
 * - The list endpoint includes `subcategories_count` (loaded with withCount).
 * - The detail endpoint includes the full `subcategories` collection.
 * Requires the related data to be loaded to avoid N+1 queries — the service
 * eager-loads it.
 */
class ServiceCategoryResource extends BaseResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'subcategories_count' => $this->whenCounted('subcategories'),
            'subcategories' => $this->whenLoaded('subcategories', fn () => ServiceSubcategoryResource::collection($this->subcategories)),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
