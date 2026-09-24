<?php

namespace App\Modules\IdentityVerification\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image backing a National ID submission, on the private `identity` disk.
 * The storage path is hidden from serialisation: administrators reach the file
 * through an authorised download, never by URL.
 */
#[Fillable([
    'identity_verification_id', 'document_type', 'file_name',
    'file_path', 'file_mime_type', 'file_size',
])]
#[Hidden(['file_path'])]
class IdentityDocument extends Model
{
    /** @var array<int, string> */
    public const TYPES = ['id_front', 'id_back', 'selfie'];

    public function verification(): BelongsTo
    {
        return $this->belongsTo(IdentityVerification::class, 'identity_verification_id');
    }
}
