<?php

namespace App\Modules\Providers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationDocument extends Model
{
    protected $fillable = [
        'verification_request_id',
        'document_type',
        'file_name',
        'file_path',
        'file_mime_type',
        'file_size',
        'description',
    ];

    /**
     * The verification request this document belongs to.
     */
    public function verificationRequest(): BelongsTo
    {
        return $this->belongsTo(VerificationRequest::class);
    }

    /**
     * Get a human-readable file size.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }
}
