<?php

namespace App\Modules\ServiceCategories\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'description', 'status', 'created_by', 'deleted_by'])]
class ServiceCategory extends Model
{
    use SoftDeletes;

    /**
     * Subcategories grouped under this category.
     */
    public function subcategories(): HasMany
    {
        return $this->hasMany(ServiceSubcategory::class, 'category_id');
    }

    /**
     * The administrator who created this category.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The administrator who deleted this category.
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Whether the category can be selected/displayed on the platform.
     */
    public function isEnabled(): bool
    {
        return $this->status === 'enabled';
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
