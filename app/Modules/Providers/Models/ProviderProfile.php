<?php

namespace App\Modules\Providers\Models;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProviderProfile extends Model
{
    protected $fillable = [
        'user_id',
        'business_name',
        'bio',
        'specialization',
        'experience_years',
        'hourly_rate',
        'location',
        'latitude',
        'longitude',
        'website',
        'social_links',
        'portfolio',
        'skills',
        'certifications',
        'languages',
        'average_rating',
        'total_reviews',
        'total_bookings',
        'completed_bookings',
        'verification_status',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'suspension_reason', 'is_featured',
        'suspended_at',
        'suspended_by',
    ];

    protected $casts = [
        'social_links' => 'array',
        'portfolio' => 'array',
        'skills' => 'array',
        'certifications' => 'array',
        'languages' => 'array',
        'hourly_rate' => 'decimal:2',
        'average_rating' => 'decimal:2',
        'verified_at' => 'datetime',
        'suspended_at' => 'datetime',
        'is_featured' => 'boolean',
    ];

    /**
     * The user that owns this provider profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The administrator who verified this provider.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The administrator who suspended this provider.
     */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    /**
     * Verification requests for this provider.
     */
    public function verificationRequests(): HasMany
    {
        return $this->hasMany(VerificationRequest::class);
    }

    /**
     * Services offered by this provider.
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'provider_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'provider_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'provider_id');
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(
            ProviderBadge::class,
            'provider_badge_assignments',
            'provider_profile_id',
            'provider_badge_id',
        )->withPivot(['assigned_by', 'assigned_at'])->withTimestamps();
    }

    /**
     * The latest verification request.
     */
    public function latestVerificationRequest(): HasOne
    {
        return $this->hasOne(VerificationRequest::class)->latestOfMany();
    }

    /**
     * Whether the provider is verified.
     */
    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * Whether the provider is pending verification.
     */
    public function isPending(): bool
    {
        return $this->verification_status === 'pending';
    }

    /**
     * Whether the provider's verification was rejected.
     */
    public function isRejected(): bool
    {
        return $this->verification_status === 'rejected';
    }

    /**
     * Whether the provider needs to submit additional information.
     */
    public function needsAdditionalInfo(): bool
    {
        return $this->verification_status === 'additional_info_required';
    }

    /**
     * Whether the provider is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}
