<?php

namespace App\Modules\Providers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationRequest extends Model
{
    protected $fillable = [
        'provider_profile_id',
        'status',
        'notes',
        'admin_notes',
        'rejection_reason',
        'additional_info_request',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /**
     * The provider profile this request belongs to.
     */
    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class);
    }

    /**
     * The administrator who reviewed this request.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Documents uploaded for this verification request.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VerificationDocument::class);
    }

    /**
     * Whether the request is pending review.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Whether the request has been approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Whether the request has been rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Whether additional information is required.
     */
    public function needsAdditionalInfo(): bool
    {
        return $this->status === 'additional_info_required';
    }
}
