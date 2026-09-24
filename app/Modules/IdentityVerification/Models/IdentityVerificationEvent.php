<?php

namespace App\Modules\IdentityVerification\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a verification's append-only history. Nothing in the
 * application updates or deletes these: they are the record of a decision the
 * platform made about a person, and must survive the account itself.
 */
#[Fillable(['identity_verification_id', 'action', 'actor_id', 'reason', 'created_at'])]
class IdentityVerificationEvent extends Model
{
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const RELEASED = 'released';

    /** Only `created_at` is meaningful for an append-only log. */
    public const UPDATED_AT = null;

    public function verification(): BelongsTo
    {
        return $this->belongsTo(IdentityVerification::class, 'identity_verification_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
