<?php

namespace App\Modules\Locations\Resources;

use App\Shared\Resources\BaseResource;

/** One place in an address picker list. */
class PhLocationResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            // region, province, city, municipality or barangay. A region's
            // children mix provinces with province-less cities (NCR).
            'level' => $this->level,
            'parent_code' => $this->parent_code,
        ];
    }
}
