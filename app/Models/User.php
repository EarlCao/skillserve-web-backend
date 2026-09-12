<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'first_name', 'last_name', 'status', 'last_login_at', 'created_by', 'user_type', 'phone', 'address', 'birthday', 'suspended_at', 'suspended_by', 'suspension_reason', 'activated_at', 'activated_by', 'banned_at', 'banned_by', 'ban_reason', 'banned_until', 'unban_reason', 'deleted_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<UserFactory> */
    use CanResetPasswordTrait, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The administrator who created this account.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The administrator who suspended this account.
     */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    /**
     * The administrator who reactivated this account.
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * The administrator who banned this account.
     */
    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    /**
     * The administrator who deleted this account.
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Audit entries whose subject is this account.
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function clientBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'client_id');
    }

    public function providerProfile(): HasOne
    {
        return $this->hasOne(ProviderProfile::class);
    }

    public function services(): HasManyThrough
    {
        return $this->hasManyThrough(
            Service::class,
            ProviderProfile::class,
            'user_id',
            'provider_id',
            'id',
            'id',
        );
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    /**
     * Whether the account is currently active (allowed to sign in).
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Client accounts are intentionally roleless; role-bearing accounts use
     * the administrator authentication surface instead.
     */
    public function isClientAccount(): bool
    {
        return $this->user_type === 'customer' && ! $this->roles()->exists();
    }

    /**
     * Provider accounts self-registered through the mobile app. They use the
     * same client session surface as customers, but carry a provider profile
     * pending administrator verification.
     */
    public function isMobileProviderAccount(): bool
    {
        return $this->user_type === 'provider'
            && ! $this->roles()->exists()
            && $this->providerProfile()->exists();
    }

    /**
     * Any account allowed through the mobile client surface (customers and
     * mobile-registered providers). Administrators are excluded.
     */
    public function isMobileAccount(): bool
    {
        return $this->isClientAccount() || $this->isMobileProviderAccount();
    }

    /**
     * Whether the account is temporarily suspended.
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Whether the account is banned (permanently or until a lift date).
     */
    public function isBanned(): bool
    {
        return $this->status === 'banned';
    }

    /**
     * Whether a temporary ban has expired and should be lifted.
     */
    public function banExpired(): bool
    {
        return $this->isBanned()
            && $this->banned_until !== null
            && $this->banned_until->isPast();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'birthday' => 'date',
            'suspended_at' => 'datetime',
            'activated_at' => 'datetime',
            'banned_at' => 'datetime',
            'banned_until' => 'datetime',
            'deleted_at' => 'datetime',
            'email_otp_expires_at' => 'datetime',
        ];
    }
}
