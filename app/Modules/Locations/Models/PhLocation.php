<?php

namespace App\Modules\Locations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One place in the Philippine Standard Geographic Code: a region, province,
 * city, municipality or barangay, identified by its 9-digit PSGC code.
 *
 * @property string $code
 * @property string $name
 * @property string $level
 * @property string|null $parent_code
 * @property string $region_code
 */
class PhLocation extends Model
{
    public const REGION = 'region';

    public const PROVINCE = 'province';

    public const CITY = 'city';

    public const MUNICIPALITY = 'municipality';

    public const BARANGAY = 'barangay';

    protected $table = 'ph_locations';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'level', 'parent_code', 'region_code'];

    /** A city or municipality: the level whose children are barangays. */
    public function isLocality(): bool
    {
        return in_array($this->level, [self::CITY, self::MUNICIPALITY], true);
    }
}
