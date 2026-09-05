<?php

namespace App\Modules\Dashboard\Resources;

use App\Shared\Resources\BaseResource;

class DashboardResource extends BaseResource
{
    public function toArray($request): array
    {
        return $this->resource;
    }
}
