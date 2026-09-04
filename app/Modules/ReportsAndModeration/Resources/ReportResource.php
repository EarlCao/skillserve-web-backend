<?php

namespace App\Modules\ReportsAndModeration\Resources;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use App\Shared\Resources\BaseResource;

/**
 * Shapes a report into the "data" portion of the standard API envelope.
 *
 * The reported item (reportable) is rendered polymorphically — each report
 * type surfaces the fields an administrator needs to review the case.
 */
class ReportResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->typeKey(),
            'reportable' => $this->whenLoaded('reportable', fn () => $this->reportableShape()),
            'reason' => $this->reason,
            'description' => $this->description,
            'status' => $this->status,
            'investigation_notes' => $this->investigation_notes ?? [],
            'investigated_by' => $this->whenLoaded('investigatedBy', fn () => $this->actor($this->investigatedBy)),
            'investigated_at' => $this->investigated_at?->toIso8601String(),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => $this->actor($this->resolvedBy)),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $this->actor($this->rejectedBy)),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'moderation_action' => $this->moderation_action,
            'action_taken_by' => $this->whenLoaded('actionTakenBy', fn () => $this->actor($this->actionTakenBy)),
            'action_taken_at' => $this->action_taken_at?->toIso8601String(),
            'action_note' => $this->action_note,
            'reporter' => $this->whenLoaded('reporter', fn () => $this->reporter ? [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'email' => $this->reporter->email,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Friendly report type key used by the admin UI.
     */
    private function typeKey(): string
    {
        return match ($this->reportable_type) {
            User::class => 'user',
            Service::class => 'service',
            Review::class => 'review',
            Message::class => 'message',
            default => 'other',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reportableShape(): ?array
    {
        $target = $this->reportable;

        if (! $target) {
            return null;
        }

        return match ($this->reportable_type) {
            User::class => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'user_type' => $target->user_type,
                'status' => $target->status,
            ],
            Service::class => [
                'id' => $target->id,
                'title' => $target->title,
                'status' => $target->status,
                'approval_status' => $target->approval_status,
                'is_hidden' => $target->is_hidden,
                'provider' => $target->relationLoaded('provider') && $target->provider ? [
                    'id' => $target->provider->id,
                    'business_name' => $target->provider->business_name,
                    'user' => $target->provider->relationLoaded('user') && $target->provider->user ? [
                        'id' => $target->provider->user->id,
                        'name' => $target->provider->user->name,
                        'email' => $target->provider->user->email,
                    ] : null,
                ] : null,
            ],
            Review::class => [
                'id' => $target->id,
                'rating' => $target->rating,
                'comment' => $target->comment,
                'status' => $target->status,
                'reviewer' => $target->relationLoaded('reviewer') && $target->reviewer ? [
                    'id' => $target->reviewer->id,
                    'name' => $target->reviewer->name,
                    'email' => $target->reviewer->email,
                ] : null,
                'provider' => $target->relationLoaded('provider') && $target->provider ? [
                    'id' => $target->provider->id,
                    'business_name' => $target->provider->business_name,
                    'user' => $target->provider->relationLoaded('user') && $target->provider->user ? [
                        'id' => $target->provider->user->id,
                        'name' => $target->provider->user->name,
                        'email' => $target->provider->user->email,
                    ] : null,
                ] : null,
                'service' => $target->relationLoaded('service') && $target->service ? [
                    'id' => $target->service->id,
                    'title' => $target->service->title,
                ] : null,
            ],
            Message::class => [
                'id' => $target->id,
                'content' => $target->content,
                'status' => $target->status,
                'sender' => $target->relationLoaded('sender') && $target->sender ? [
                    'id' => $target->sender->id,
                    'name' => $target->sender->name,
                    'email' => $target->sender->email,
                ] : null,
                'receiver' => $target->relationLoaded('receiver') && $target->receiver ? [
                    'id' => $target->receiver->id,
                    'name' => $target->receiver->name,
                    'email' => $target->receiver->email,
                ] : null,
                'created_at' => $target->created_at?->toIso8601String(),
            ],
            default => ['id' => $target->id],
        };
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function actor(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
