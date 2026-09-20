<?php

namespace App\Modules\ClientAuthentication\Models;

use App\Shared\Enums\AccountRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * A mobile sign-up that is waiting for its emailed OTP.
 *
 * Nothing exists in `users` while this row does: the account is created
 * only when the code is confirmed, so an abandoned sign-up never holds an
 * email address hostage. The row is notifiable so the verification code
 * can be mailed without a user account.
 */
class PendingRegistration extends Model
{
    use Notifiable;

    /** How long an unfinished registration is kept before it is pruned. */
    public const LIFETIME_HOURS = 24;

    protected $fillable = [
        'email',
        'first_name',
        'last_name',
        'password',
        'role_id',
        'business_name',
        'specialization',
        'experience_years',
        'bio',
        'email_otp_hash',
        'email_otp_expires_at',
        'email_otp_attempts',
        'expires_at',
    ];

    protected $hidden = ['password', 'email_otp_hash'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role_id' => 'integer',
            'experience_years' => 'integer',
            'email_otp_attempts' => 'integer',
            'email_otp_expires_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** Registrations that are still within their verification window. */
    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function isProvider(): bool
    {
        return $this->role_id === AccountRole::Provider->value;
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** Mail notifications go to the address being verified. */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}
