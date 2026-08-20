<?php

namespace App\Modules\Services\Models;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'provider_id', 'category_id', 'subcategory_id', 'title', 'description',
    'price', 'price_type', 'currency', 'duration', 'location',
    'status', 'approval_status', 'rejection_reason', 'is_featured', 'is_hidden',
    'total_bookings', 'completed_bookings', 'average_rating', 'total_reviews',
    'created_by', 'updated_by', 'approved_by', 'approved_at', 'deleted_by',
])]
class Service extends Model
{
    use SoftDeletes;

    /**
     * The provider that owns this service.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_id');
    }

    /**
     * The service category.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * The service subcategory (optional).
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ServiceSubcategory::class, 'subcategory_id');
    }

    /**
     * The administrator who created this service.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The administrator who last updated this service.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The administrator who approved this service.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The administrator who deleted this service.
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Whether the service is published and visible.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Whether the service is approved.
     */
    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /**
     * Whether the service is hidden from public view.
     */
    public function isHidden(): bool
    {
        return $this->is_hidden === true;
    }

    /**
     * Whether the service is featured.
     */
    public function isFeatured(): bool
    {
        return $this->is_featured === true;
    }

    /**
     * Whether the service is pending approval.
     */
    public function isPending(): bool
    {
        return $this->approval_status === 'pending';
    }

    /**
     * Whether the service was rejected.
     */
    public function isRejected(): bool
    {
        return $this->approval_status === 'rejected';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_featured' => 'boolean',
            'is_hidden' => 'boolean',
            'average_rating' => 'decimal:2',
            'approved_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
