<?php

namespace App\Modules\ClientCommunication\Models;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer's conversation with a provider before any booking — questions
 * about a job, a price or availability. One per customer–provider pair; the
 * customer starts it, either side writes in it.
 */
#[Fillable(['customer_id', 'provider_profile_id'])]
class Inquiry extends Model
{
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'inquiry_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'inquiry_id')->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->where('status', 'active'),
        );
    }
}
