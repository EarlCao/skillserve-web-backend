<?php

namespace App\Shared\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base class for API resources.
 *
 * A resource shapes a single model into the "data" portion of the standard
 * API envelope (the envelope itself is built by ApiResponder, so resources
 * must NOT wrap responses here).
 *
 * Note: toArray() is NOT re-declared here — JsonResource already defines a
 * concrete implementation and PHP forbids making it abstract again. Concrete
 * resources simply override toArray($request) with their own shape.
 */
abstract class BaseResource extends JsonResource {}
