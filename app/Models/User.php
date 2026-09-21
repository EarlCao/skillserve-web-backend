<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientPreferences\Models\ClientPreference;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use App\Shared\Enums\AccountRole;
use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

#[Fillable(['name', 'email', 'password', 'first_name', 'last_name', 'status', 'last_login_at', 'created_by', 'role_id', 'user_type', 'phone', 'address', 'birthday', 'suspended_at', 'suspended_by', 'suspension_reason', 'activated_at', 'activated_by', 'banned_at', 'banned_by', 'ban_reason', 'banned_until', 'unban_reason', 'deleted_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<UserFactory> */
    use CanResetPasswordTrait, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        // New accounts are customers unless a role is given.
        static::creating(function (User $user): void {
            $user->role_id ??= AccountRole::Customer->value;
        });

        // role_id is the source of truth: keep the staff role assignment
        // (which carries permissions) in step with it.
        static::saved(function (User $user): void {
            if ($user->wasRecentlyCreated || $user->wasChanged('role_id')) {
                $user->syncStaffRoleFromRoleId();
            }
        });
    }

    /**
     * The role that classifies this account (users.role_id → roles.id).
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(config('permission.models.role'), 'role_id');
    }

    /**
     * Account type derived from the role: "customer", "provider" or "admin".
     * Setting it (e.g. `'user_type' => 'provider'`) sets role_id.
     */
    protected function userType(): Attribute
    {
        return Attribute::make(
            get: fn () => AccountRole::userTypeFor($this->role_id === null ? null : (int) $this->role_id),
            set: fn (string $value) => ['role_id' => AccountRole::idForUserType($value)],
        );
    }

    public function isAdministrator(): bool
    {
        return $this->role_id !== null && ! in_array((int) $this->role_id, AccountRole::accountTypeIds(), true);
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('role_id', AccountRole::Customer->value);
    }

    public function scopeProviders(Builder $query): Builder
    {
        return $query->where('role_id', AccountRole::Provider->value);
    }

    /**
     * Customer and provider accounts (the mobile app's users).
     */
    public function scopeMobileAccounts(Builder $query): Builder
    {
        return $query->whereIn('role_id', AccountRole::accountTypeIds());
    }

    /**
     * Assign role_id's staff role, or clear staff roles for mobile accounts.
     */
    public function syncStaffRoleFromRoleId(): void
    {
        $roleId = (int) $this->role_id;

        if (in_array($roleId, AccountRole::accountTypeIds(), true)) {
            if ($this->roles()->exists()) {
                $this->roles()->detach();
            }
        } elseif (! $this->roles()->whereKey($roleId)->exists()) {
            $this->roles()->sync([$roleId]);
        }

        $this->unsetRelation('roles');
    }

    /**
     * Point role_id at the account's primary staff role after its role
     * assignment changed (super-admin, then admin, then the lowest custom
     * role); an account left without staff roles becomes a customer.
     */
    public function syncRoleIdFromStaffRoles(): void
    {
        $roleIds = $this->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();

        $roleId = match (true) {
            in_array(AccountRole::SuperAdmin->value, $roleIds, true) => AccountRole::SuperAdmin->value,
            in_array(AccountRole::Admin->value, $roleIds, true) => AccountRole::Admin->value,
            $roleIds !== [] => min($roleIds),
            $this->isAdministrator() => AccountRole::Customer->value,
            default => (int) $this->role_id,
        };

        if ($roleId !== (int) $this->role_id) {
            $this->forceFill(['role_id' => $roleId])->saveQuietly();
        }

        $this->unsetRelation('roles');
    }

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

    /** Providers this customer saved in the app. */
    public function favoriteProviders(): BelongsToMany
    {
        return $this->belongsToMany(ProviderProfile::class, 'favorite_providers', 'user_id', 'provider_profile_id')
            ->withTimestamps();
    }

    public function providerProfile(): HasOne
    {
        return $this->hasOne(ProviderProfile::class);
    }

    /**
     * The account's mobile settings. Absent until first read, in which case
     * ClientPreference::defaults() apply.
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(ClientPreference::class);
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
     * Customer accounts (role 4) use the mobile client surface.
     */
    public function isClientAccount(): bool
    {
        return (int) $this->role_id === AccountRole::Customer->value;
    }

    /**
     * Provider accounts (role 3) self-registered through the mobile app, with
     * a provider profile pending or past administrator verification.
     */
    public function isMobileProviderAccount(): bool
    {
        return (int) $this->role_id === AccountRole::Provider->value
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
