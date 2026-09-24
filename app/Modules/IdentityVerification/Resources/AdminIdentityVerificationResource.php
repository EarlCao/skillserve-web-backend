<?php

namespace App\Modules\IdentityVerification\Resources;

use App\Shared\Resources\BaseResource;

/**
 * A submission as the reviewer sees it.
 *
 * The card number is never included — not even for an administrator. The
 * reviewer confirms the number by opening the ID image through the authorised
 * download; putting it in a JSON list would spread it into logs, browser
 * caches and error reporters for every row an administrator scrolls past.
 * `id_number_last4` is enough to tie a row to a submission.
 */
class AdminIdentityVerificationResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'id_number_last4' => $this->id_number_last4,

            // As printed on the card, for the reviewer to match the image
            // against.
            'full_name' => $this->full_name,
            'birthdate' => $this->birthdate?->toDateString(),

            'account' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'account_type' => $this->user->user_type,
            ] : null),

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->only(['id', 'name'])),
            'rejection_reason' => $this->rejection_reason,

            'documents_count' => $this->whenCounted('documents'),
            'documents_purge_after' => $this->documents_purge_after?->toIso8601String(),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document): array => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'file_name' => $document->file_name,
                'file_mime_type' => $document->file_mime_type,
                'file_size' => $document->file_size,
            ])->values()->all()),

            'history' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event): array => [
                'action' => $event->action,
                'reason' => $event->reason,
                'actor' => $event->actor?->only(['id', 'name']),
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
