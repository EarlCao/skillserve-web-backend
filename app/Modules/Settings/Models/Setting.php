<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = ['group', 'name', 'locked', 'payload'];

    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'payload' => 'array',
        ];
    }
}
