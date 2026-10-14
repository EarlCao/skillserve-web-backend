<?php

namespace App\Modules\ReportsAndModeration\Models;

use App\Shared\Helpers\PrivateImages;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo the reporter sent as proof, on the private `report_attachments` disk. */
#[Fillable(['report_id', 'uploaded_by', 'path', 'mime_type', 'size_bytes'])]
#[Hidden(['path'])]
class ReportAttachment extends Model
{
    public const DISK = 'report_attachments';

    /** How many photos one report may carry. */
    public const MAX_PER_REPORT = 5;

    protected static function booted(): void
    {
        // The photo goes with its row.
        static::deleted(fn (self $attachment) => PrivateImages::deleteAfterCommit($attachment::DISK, $attachment->path));
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
