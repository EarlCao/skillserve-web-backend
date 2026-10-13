<?php

namespace App\Modules\ClientCommunication\Resources;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Resources\ReportResource;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use App\Shared\Resources\BaseResource;

/**
 * A report as the person who filed it may see it.
 *
 * Deliberately narrower than the administrator's {@see ReportResource}:
 * the reporter learns the status and the outcome, but never the internal
 * investigation notes, the moderators' identities, or what was done to the
 * other account — those are moderation internals.
 */
class ClientReportResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            // What was reported: a person, a review, a message or a service.
            'subject_type' => match ($this->reportable_type) {
                Review::class => 'review',
                Message::class => 'message',
                Service::class => 'service',
                default => 'user',
            },
            'reason' => $this->reason,
            'description' => $this->description,
            'status' => $this->status,
            // Who was reported: a name only, and only because the reporter
            // named them in the first place.
            'reported' => $this->whenLoaded('reportable', fn () => $this->reportedShape()),
            'outcome' => $this->outcome(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * What the reporter is told about the result: the resolution note when the
     * case was upheld, the rejection reason when it was not, and nothing while
     * it is still being looked at.
     */
    private function outcome(): ?string
    {
        return match ($this->status) {
            'resolved' => $this->resolution_note,
            'rejected' => $this->reject_reason,
            default => null,
        };
    }

    /**
     * A provider is named as the marketplace names them — their business name
     * when they have one — so filing a report never reveals the personal name
     * behind a business. Anyone else is named by their account.
     */
    private function personName(User $user): ?string
    {
        $profile = $user->providerProfile;

        if ($profile === null) {
            return $user->name;
        }

        return $profile->setRelation('user', $user)->displayName();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reportedShape(): ?array
    {
        $target = $this->reportable;

        return match (true) {
            $target instanceof User => ['name' => $this->personName($target)],
            // The reporter already saw this text; it reminds them what they
            // flagged. Nothing else about the author is exposed.
            $target instanceof Review => [
                'name' => $target->reviewer?->name,
                'excerpt' => $target->comment,
            ],
            $target instanceof Message => [
                'name' => $target->sender?->name,
                'excerpt' => $target->content,
            ],
            // A public listing: its title and who offers it.
            $target instanceof Service => [
                'name' => $target->provider?->business_name,
                'excerpt' => $target->title,
            ],
            default => null,
        };
    }
}
