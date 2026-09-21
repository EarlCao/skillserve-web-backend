<?php

namespace App\Modules\ReportsAndModeration\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'reportable_type', 'reportable_id', 'reporter_id', 'reason', 'description',
    'status', 'investigation_notes', 'investigated_by', 'investigated_at',
    'resolved_by', 'resolved_at', 'resolution_note',
    'rejected_by', 'rejected_at', 'reject_reason',
    'moderation_action', 'action_taken_by', 'action_taken_at', 'action_note',
    'deleted_by',
])]
class Report extends Model
{
    use SoftDeletes;

    /**
     * Why someone can be reported. Stored as the key so the reason survives
     * copy changes; each client owns its own wording.
     *
     * @var array<int, string>
     */
    public const REASONS = [
        'service_quality',
        'no_show',
        'safety_concern',
        'payment_dispute',
        'misleading_information',
        'harassment',
        'inappropriate_content',
        'spam',
        'other',
    ];

    /**
     * Reasons older reports still carry but that can no longer be filed.
     * Moderators must still be able to filter by them.
     *
     * @var array<int, string>
     */
    public const LEGACY_REASONS = ['fraud', 'misleading', 'offensive'];

    /** @return array<int, string> */
    public static function filterableReasons(): array
    {
        return [...self::REASONS, ...self::LEGACY_REASONS];
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function investigatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigated_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function actionTakenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_taken_by');
    }

    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInvestigating(): bool
    {
        return $this->status === 'investigating';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Resolved and rejected reports are terminal — no further lifecycle
     * transitions (investigate, note, resolve, reject, action) are allowed.
     */
    public function isTerminal(): bool
    {
        return $this->isResolved() || $this->isRejected();
    }

    protected function casts(): array
    {
        return [
            'investigation_notes' => 'array',
            'investigated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'action_taken_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
