<?php

namespace App\Modules\DataManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataArchive extends Model
{
    protected $fillable = ['resource_type', 'resource_id', 'archived_by', 'archived_at', 'previous_state'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'previous_state' => 'array'];
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
