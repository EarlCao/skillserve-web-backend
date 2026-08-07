<?php

namespace App\Modules\Users\Resources;

use App\Modules\Authentication\Resources\UserResource;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Shapes a platform user for the management screens: everything from
 * UserResource plus the profile, account state (status/verification) and
 * moderation history (suspension / activation / ban / deletion).
 *
 * Requires the moderation actors and (for the profile) the recent activity
 * to be loaded to avoid N+1 queries — the service eager-loads them.
 */
class UserManagementResource extends UserResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'user_type' => $this->user_type,
            'phone' => $this->phone,
            'address' => $this->address,
            'birthday' => $this->birthday?->toDateString(),
            // Avatars arrive with the media/profile module — initials are used
            // until then.
            'profile_photo_url' => null,
            'status' => $this->status,
            'verification' => $this->email_verified_at ? 'verified' : 'unverified',
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspended_by' => $this->whenLoaded('suspendedBy', fn () => $this->suspendedBy ? [
                'id' => $this->suspendedBy->id,
                'name' => $this->suspendedBy->name,
            ] : null),
            'suspension_reason' => $this->suspension_reason,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'activated_by' => $this->whenLoaded('activatedBy', fn () => $this->activatedBy ? [
                'id' => $this->activatedBy->id,
                'name' => $this->activatedBy->name,
            ] : null),
            'banned_at' => $this->banned_at?->toIso8601String(),
            'banned_by' => $this->whenLoaded('bannedBy', fn () => $this->bannedBy ? [
                'id' => $this->bannedBy->id,
                'name' => $this->bannedBy->name,
            ] : null),
            'ban_reason' => $this->ban_reason,
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'deleted_by' => $this->whenLoaded('deletedBy', fn () => $this->deletedBy ? [
                'id' => $this->deletedBy->id,
                'name' => $this->deletedBy->name,
            ] : null),
            'summary' => [
                // Placeholder counters — the Services / Bookings / Ratings /
                // Reviews modules land in later phases; these stay at zero
                // until those relations exist.
                'services_count' => 0,
                'bookings_count' => 0,
                'ratings_count' => 0,
                'reviews_count' => 0,
                'recent_activity' => $this->whenLoaded('activities', fn () => $this->activities->map(
                    fn (Activity $activity) => [
                        'id' => $activity->id,
                        'description' => $activity->description,
                        'properties' => $activity->properties->toArray(),
                        'causer' => $activity->causer ? [
                            'id' => $activity->causer->id,
                            'name' => $activity->causer->name,
                        ] : null,
                        'logged_at' => $activity->created_at?->toIso8601String(),
                    ],
                )),
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ]);
    }
}
