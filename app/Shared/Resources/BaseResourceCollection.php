<?php

namespace App\Shared\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Base class for API resource collections.
 *
 * Use with ApiResponder::paginated() for consistent pagination metadata.
 */
abstract class BaseResourceCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
        ];
    }
}
