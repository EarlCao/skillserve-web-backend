<?php

namespace App\Modules\ClientAuthentication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientRefreshToken extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'family_id',
        'token_hash',
        'replaced_by',
        'expires_at',
        'revoked_at',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
