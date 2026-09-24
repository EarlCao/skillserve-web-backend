<?php

namespace App\Modules\IdentityVerification\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account's National ID verification: where it stands, and the minimum
 * needed to prove the ID has not already been used.
 *
 * unverified ──submit──▶ pending ──approve──▶ verified
 *                            └───reject────▶ rejected ──submit──▶ pending
 *
 * The card number is never stored in the clear. `id_number_hash` is the only
 * column ever compared and the only one the uniqueness index can use;
 * `id_number_encrypted` exists so an administrator can settle a disputed
 * match, and is cleared when the ID is released.
 *
 * Both sensitive columns are hidden from array/JSON serialisation as a
 * backstop — resources are expected to select fields explicitly, but a
 * `toArray()` anywhere must not leak the number.
 */
#[Fillable([
    'user_id', 'status', 'id_number_hash', 'id_number_encrypted', 'id_number_last4',
    'full_name', 'birthdate', 'submitted_at', 'reviewed_at', 'reviewed_by',
    'rejection_reason', 'released_at', 'documents_purge_after',
])]
#[Hidden(['id_number_hash', 'id_number_encrypted'])]
class IdentityVerification extends Model
{
    public const UNVERIFIED = 'unverified';

    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    /** States from which the holder may submit (or resubmit) documents. */
    public const SUBMITTABLE = [self::UNVERIFIED, self::REJECTED];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(IdentityDocument::class);
    }

    /** Append-only decision history; outlives the account it belonged to. */
    public function events(): HasMany
    {
        return $this->hasMany(IdentityVerificationEvent::class)->orderBy('id');
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    public function canSubmit(): bool
    {
        return in_array($this->status, self::SUBMITTABLE, true);
    }

    protected function casts(): array
    {
        return [
            // Laravel's encryption is randomised, which is exactly why the
            // hash column exists for comparison.
            'id_number_encrypted' => 'encrypted',
            'birthdate' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'released_at' => 'datetime',
            'documents_purge_after' => 'datetime',
        ];
    }
}
