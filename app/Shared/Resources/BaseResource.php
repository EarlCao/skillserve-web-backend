<?php

namespace App\Shared\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base class for API resources.
 *
 * A resource shapes a single model into the "data" portion of the standard
 * API envelope (the envelope itself is built by ApiResponder, so resources
 * must NOT wrap responses here).
 */
abstract class BaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    abstract public function toArray($request): array;
}
