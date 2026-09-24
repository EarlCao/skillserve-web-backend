<?php

namespace App\Modules\IdentityVerification\Services;

use App\Models\User;
use App\Modules\IdentityVerification\Events\IdentityVerificationApproved;
use App\Modules\IdentityVerification\Events\IdentityVerificationRejected;
use App\Modules\IdentityVerification\Events\IdentityVerificationSubmitted;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\IdentityVerification\Models\IdentityVerificationEvent;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Enums\AccountRole;
use App\Shared\Exceptions\ApiException;
use App\Shared\Helpers\PageSize;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The account holder's side of National ID verification: see where it stands,
 * and submit the card for an administrator to review.
 *
 * unverified / rejected ──submit──▶ pending
 *
 * Customers and providers use the same pipeline — identity is identity — which
 * is why this lives in its own module rather than inside the provider one.
 *
 * Files go to the private `identity` disk and are never served directly.
 */
class IdentityVerificationService extends BaseService
{
    public function __construct(private readonly NationalIdHasher $hasher) {}

    /** The account's record, created on first read so callers always have one. */
    public function forUser(User $user): IdentityVerification
    {
        return IdentityVerification::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['status' => IdentityVerification::UNVERIFIED],
        );
    }

    public function show(User $user): IdentityVerification
    {
        return $this->forUser($user)->load('documents');
    }

    /**
     * @param  array{id_number: string, full_name: string, birthdate: string}  $data
     * @param  array<int, array{type: string, file: UploadedFile}>  $documents
     */
    public function submit(User $user, array $data, array $documents): IdentityVerification
    {
        $record = $this->forUser($user);

        if (! $record->canSubmit()) {
            throw new ApiException(
                $record->status === IdentityVerification::PENDING
                    ? 'Your National ID is already waiting for review.'
                    : 'Your identity is already verified.',
                422,
                errors: ['status' => ['This account is '.$record->status.'.']],
            );
        }

        $hash = $this->hasher->hash($data['id_number']);

        // Write the files before taking the row lock, so storage I/O never
        // holds it; they are removed again if the database refuses.
        $stored = $this->storeFiles($record, $documents);

        try {
            $record = $this->transaction(function () use ($record, $data, $hash, $stored, $user): IdentityVerification {
                $record = IdentityVerification::query()->lockForUpdate()->findOrFail($record->id);

                if (! $record->canSubmit()) {
                    throw new ApiException('Your National ID is already waiting for review.', 422);
                }

                $this->assertIdNotInUse($hash, $record->id);

                $record->update([
                    'status' => IdentityVerification::PENDING,
                    'id_number_hash' => $hash,
                    'id_number_encrypted' => $this->hasher->normalise($data['id_number']),
                    'id_number_last4' => $this->hasher->last4($data['id_number']),
                    'full_name' => $data['full_name'],
                    'birthdate' => $data['birthdate'],
                    'submitted_at' => now(),
                    'rejection_reason' => null,
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                ]);

                // A resubmission replaces the previous images: the old ones
                // were rejected and keeping them only widens exposure.
                $this->deleteDocumentsOf($record);

                foreach ($stored as $item) {
                    $record->documents()->create([
                        'document_type' => $item['type'],
                        'file_name' => $item['file']->getClientOriginalName(),
                        'file_path' => $item['path'],
                        'file_mime_type' => $item['file']->getMimeType() ?? 'application/octet-stream',
                        'file_size' => $item['file']->getSize(),
                    ]);
                }

                $this->recordEvent($record, IdentityVerificationEvent::SUBMITTED, $user);

                return $record;
            });
        } catch (QueryException $exception) {
            $this->deleteFiles($stored);

            // The partial unique index is what actually closes the race
            // between two accounts claiming one ID at the same moment.
            if (str_contains($exception->getMessage(), 'identity_verifications_active_id_number')) {
                throw $this->idInUseException();
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $this->deleteFiles($stored);

            throw $exception;
        }

        event(new IdentityVerificationSubmitted(verification: $record, actor: $user));

        return $record->load('documents');
    }

    /**
     * Paginated review queue.
     *
     * Note what is NOT searchable: the card number. Searching by it would
     * require either storing it in the clear or hashing the search term, and
     * the second turns this endpoint into a "does SkillServe know this ID?"
     * oracle for anyone holding a stolen card.
     *
     * @param  array{status?: string, search?: string, account_type?: string, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = IdentityVerification::query()
            ->whereNotNull('user_id')
            ->with(['user:id,name,email,role_id', 'reviewedBy:id,name'])
            ->withCount('documents');

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->whereHas('user', fn (Builder $user) => $user
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
        }

        if ($accountType = trim((string) ($filters['account_type'] ?? ''))) {
            $roleId = $accountType === 'provider'
                ? AccountRole::Provider->value
                : AccountRole::Customer->value;

            $query->whereHas('user', fn (Builder $user) => $user->where('role_id', $roleId));
        }

        $sort = in_array($filters['sort'] ?? null, ['submitted_at', 'reviewed_at', 'created_at'], true)
            ? $filters['sort']
            : 'submitted_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('id')->paginate(PageSize::from($filters));
    }

    public function showForAdmin(IdentityVerification $record): IdentityVerification
    {
        return $record->load([
            'user:id,name,email,role_id',
            'reviewedBy:id,name',
            'documents',
            'events.actor:id,name',
        ]);
    }

    /** Confirm the ID belongs to the account holder. */
    public function approve(IdentityVerification $record, User $actor, ?string $notes = null): IdentityVerification
    {
        return $this->decide($record, $actor, IdentityVerification::VERIFIED, $notes, function (IdentityVerification $record, User $actor, ?string $notes): void {
            event(new IdentityVerificationApproved(verification: $record, actor: $actor, notes: $notes));
        });
    }

    /** Refuse the submission, telling the holder why so they can resubmit. */
    public function reject(IdentityVerification $record, User $actor, string $reason): IdentityVerification
    {
        return $this->decide($record, $actor, IdentityVerification::REJECTED, $reason, function (IdentityVerification $record, User $actor, string $reason): void {
            event(new IdentityVerificationRejected(verification: $record, actor: $actor, reason: $reason));
        });
    }

    /**
     * Both decisions share the same guards: only a pending submission can be
     * decided, the row is locked so two reviewers cannot both decide it, and
     * the images start their retention clock the moment a decision lands.
     */
    private function decide(
        IdentityVerification $record,
        User $actor,
        string $status,
        ?string $reason,
        callable $emit,
    ): IdentityVerification {
        $decided = $this->transaction(function () use ($record, $actor, $status, $reason): IdentityVerification {
            $record = IdentityVerification::query()->lockForUpdate()->findOrFail($record->id);

            if ($record->status !== IdentityVerification::PENDING) {
                throw new ApiException(
                    'This submission has already been reviewed.',
                    409,
                    errors: ['status' => ['This submission is '.$record->status.'.']],
                );
            }

            $retentionDays = (int) app(SettingsService::class)
                ->value('identity', 'identity_document_retention_days');

            $record->update([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'rejection_reason' => $status === IdentityVerification::REJECTED ? $reason : null,
                // The images are only needed while under review or dispute.
                'documents_purge_after' => now()->addDays(max(1, $retentionDays)),
            ]);

            $this->recordEvent(
                $record,
                $status === IdentityVerification::VERIFIED
                    ? IdentityVerificationEvent::APPROVED
                    : IdentityVerificationEvent::REJECTED,
                $actor,
                $reason,
            );

            return $record;
        });

        $emit($decided, $actor, $reason);

        return $this->showForAdmin($decided);
    }

    /**
     * Free the National ID once the account it belonged to is permanently
     * deleted, so the person can sign up again.
     *
     * The hash is deliberately kept: it is pseudonymous, it cannot be reversed
     * without the server pepper, and it is what lets the platform recognise a
     * previously-removed ID. The encrypted number and the images are dropped,
     * because nothing needs them any more.
     */
    public function release(IdentityVerification $record): void
    {
        $this->transaction(function () use ($record): void {
            $record = IdentityVerification::query()->lockForUpdate()->find($record->id);

            if ($record === null || $record->released_at !== null) {
                return;
            }

            $this->deleteDocumentsOf($record);

            $record->update([
                'released_at' => now(),
                'id_number_encrypted' => null,
                'documents_purge_after' => null,
            ]);

            $this->recordEvent($record, IdentityVerificationEvent::RELEASED, null);
        });
    }

    /** Append-only history. Never updated, never deleted. */
    public function recordEvent(
        IdentityVerification $record,
        string $action,
        ?User $actor,
        ?string $reason = null,
    ): void {
        IdentityVerificationEvent::create([
            'identity_verification_id' => $record->id,
            'action' => $action,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** Remove a record's stored images, on disk and in the database. */
    public function deleteDocumentsOf(IdentityVerification $record): void
    {
        $documents = $record->documents()->get();

        foreach ($documents as $document) {
            Storage::disk(config('identity.disk'))->delete($document->file_path);
        }

        $record->documents()->delete();
        $record->unsetRelation('documents');
    }

    private function assertIdNotInUse(string $hash, int $ignoreId): void
    {
        $taken = IdentityVerification::query()
            ->where('id_number_hash', $hash)
            ->whereNull('released_at')
            ->whereKeyNot($ignoreId)
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            throw $this->idInUseException();
        }
    }

    /**
     * Deliberately vague: confirming *which* account holds an ID would turn
     * this endpoint into a lookup oracle for someone holding a stolen card.
     */
    private function idInUseException(): ApiException
    {
        return new ApiException(
            'That National ID is already linked to a SkillServe account.',
            422,
            errors: ['id_number' => ['This National ID cannot be used to verify this account.']],
        );
    }

    /**
     * @param  array<int, array{type: string, file: UploadedFile}>  $documents
     * @return array<int, array{type: string, file: UploadedFile, path: string}>
     */
    private function storeFiles(IdentityVerification $record, array $documents): array
    {
        $stored = [];

        foreach ($documents as $document) {
            $file = $document['file'];

            // Random names: the original filename is attacker-controlled and
            // must never determine where a file lands.
            $path = $file->storeAs(
                "identity/{$record->id}",
                Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()),
                ['disk' => config('identity.disk')],
            );

            if ($path === false) {
                $this->deleteFiles($stored);

                throw new ApiException('A document could not be saved. Please try again.', 500);
            }

            $stored[] = ['type' => $document['type'], 'file' => $file, 'path' => $path];
        }

        return $stored;
    }

    /** @param  array<int, array{path: string}>  $stored */
    private function deleteFiles(array $stored): void
    {
        foreach ($stored as $item) {
            Storage::disk(config('identity.disk'))->delete($item['path']);
        }
    }
}
