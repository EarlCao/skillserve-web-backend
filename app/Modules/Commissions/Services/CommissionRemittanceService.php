<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\Commissions\Events\CommissionRemittanceConfirmed;
use App\Modules\Commissions\Events\CommissionRemittanceRejected;
use App\Modules\Commissions\Events\CommissionRemittanceSubmitted;
use App\Modules\Commissions\Models\CommissionRemittance;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Helpers\PageSize;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A provider pays SkillServe its commission by GCash, outside the app, then
 * submits the GCash reference number. An administrator finds that reference in
 * SkillServe's GCash history and confirms it, which settles every booking the
 * payment covered — or rejects it with a reason, and the provider tries again.
 *
 *   pending ──confirm──▶ confirmed   (covered bookings settled)
 *      └─────reject───▶ rejected
 *
 * A submission alone settles nothing: anyone can type a reference number, so
 * the provider stays blocked until a person has seen the money arrive.
 *
 * Receipt screenshots go to the private `commission_remittances` disk and are
 * only ever opened through an authorized download.
 */
class CommissionRemittanceService extends BaseService
{
    public const DISK = 'commission_remittances';

    public function __construct(
        private readonly CommissionLedger $ledger,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Where providers send the commission, from System Settings → Commission
     * payments. `configured` is false until both the name and number are set.
     *
     * @return array{gcash_account_name: string, gcash_number: string, payment_instructions: string, configured: bool}
     */
    public function account(): array
    {
        $value = fn (string $name): string => trim((string) $this->settings->value('commission_payments', $name));

        $name = $value('gcash_account_name');
        $number = $value('gcash_number');

        return [
            'gcash_account_name' => $name,
            'gcash_number' => $number,
            'payment_instructions' => $value('payment_instructions'),
            'configured' => $name !== '' && $number !== '',
        ];
    }

    /**
     * Record the provider's claim to have paid everything they currently owe.
     *
     * The bookings it covers, and what each owed, are fixed now: a commission
     * that becomes outstanding while the claim waits is not part of it.
     */
    public function submit(
        User $user,
        ProviderProfile $profile,
        string $reference,
        float $amountSent,
        ?UploadedFile $proof = null,
    ): CommissionRemittance {
        // Write the file before taking any lock, so storage I/O never holds
        // one; it is removed again if the database refuses the claim.
        $stored = $proof ? $this->storeProof($profile, $proof) : null;

        try {
            $remittance = $this->transaction(function () use ($profile, $reference, $amountSent, $stored): CommissionRemittance {
                // Serialises this provider's submissions.
                ProviderProfile::query()->whereKey($profile->id)->lockForUpdate()->first();

                if ($this->pendingFor($profile->id) !== null) {
                    throw $this->alreadyPendingException();
                }

                $bookings = $this->ledger->outstandingQuery($profile->id)
                    ->lockForUpdate()
                    ->get(['id', 'platform_fee']);

                if ($bookings->isEmpty()) {
                    throw new ApiException(
                        'You have no outstanding commission to pay.',
                        422,
                        errors: ['amount_sent' => ['There is nothing to pay right now.']],
                    );
                }

                $due = round((float) $bookings->sum('platform_fee'), 2);

                if (round($amountSent, 2) < $due) {
                    $owed = '₱'.number_format($due, 2);

                    throw new ApiException(
                        "You owe {$owed}. Send the full amount, then submit the reference number.",
                        422,
                        errors: ['amount_sent' => ["The amount sent must be at least {$owed}."]],
                    );
                }

                // Only a confirmed claim reserves a reference. Pending claims may
                // share one, or typing someone else's receipt would lock the
                // real payer out; the reviewer is shown the clash instead.
                if ($this->referenceConfirmed($reference)) {
                    throw $this->referenceConfirmedException();
                }

                $remittance = CommissionRemittance::create([
                    'provider_profile_id' => $profile->id,
                    'amount_due' => $due,
                    'amount_sent' => round($amountSent, 2),
                    'reference' => $reference,
                    'proof_path' => $stored['path'] ?? null,
                    'proof_mime_type' => $stored['mime_type'] ?? null,
                    'status' => CommissionRemittance::PENDING,
                ]);

                $remittance->bookings()->attach(
                    $bookings->mapWithKeys(fn ($booking): array => [
                        $booking->id => ['amount' => $booking->platform_fee],
                    ])->all(),
                );

                return $remittance;
            });
        } catch (QueryException $exception) {
            $this->deleteProof($stored['path'] ?? null);

            // The partial unique indexes are what actually close the race
            // between two submissions landing at the same moment.
            if (str_contains($exception->getMessage(), 'commission_remittances_one_pending_per_provider')) {
                throw $this->alreadyPendingException();
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $this->deleteProof($stored['path'] ?? null);

            throw $exception;
        }

        event(new CommissionRemittanceSubmitted(remittance: $remittance, actor: $user));

        return $remittance->loadCount('bookings');
    }

    /**
     * The money arrived: settle every booking the payment covered. A booking
     * that stopped being outstanding meanwhile (waived, voided, or settled by
     * hand) is skipped — the ledger refuses to settle it twice.
     *
     * One GCash receipt pays once: when another claim on the same reference
     * has already been confirmed, this one is refused.
     */
    public function confirm(CommissionRemittance $remittance, User $actor, ?string $notes = null): CommissionRemittance
    {
        try {
            $confirmed = $this->transaction(fn (): CommissionRemittance => $this->confirmLocked($remittance, $actor, $notes));
        } catch (QueryException $exception) {
            // The partial unique index closes the race between two reviewers
            // confirming two claims on one reference at the same moment.
            if (str_contains($exception->getMessage(), 'commission_remittances_reference_confirmed')) {
                throw $this->referenceConfirmedException(409);
            }

            throw $exception;
        }

        event(new CommissionRemittanceConfirmed(remittance: $confirmed, actor: $actor));

        return $this->loadForAdmin($confirmed);
    }

    private function confirmLocked(CommissionRemittance $remittance, User $actor, ?string $notes): CommissionRemittance
    {
        $remittance = $this->lockPending($remittance);

        if ($this->referenceConfirmed($remittance->reference)) {
            throw $this->referenceConfirmedException(409);
        }

        foreach ($remittance->bookings()->get() as $booking) {
            if ($booking->commission_status !== CommissionLedger::OUTSTANDING) {
                continue;
            }

            $this->ledger->settle($booking, $actor, 'gcash', $remittance->reference, $notes, $remittance->id);
        }

        $remittance->update([
            'status' => CommissionRemittance::CONFIRMED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        return $remittance;
    }

    /** The reference was not found or the amount was short; tell the provider why. */
    public function reject(CommissionRemittance $remittance, User $actor, string $reason): CommissionRemittance
    {
        $rejected = $this->transaction(function () use ($remittance, $actor, $reason): CommissionRemittance {
            $remittance = $this->lockPending($remittance);

            $remittance->update([
                'status' => CommissionRemittance::REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ]);

            return $remittance;
        });

        event(new CommissionRemittanceRejected(remittance: $rejected, actor: $actor));

        return $this->loadForAdmin($rejected);
    }

    /**
     * The administrators' review queue.
     *
     * @param  array{status?: string, provider_id?: int, search?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = $this->withAdminRelations(CommissionRemittance::query());

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if ($providerId = $filters['provider_id'] ?? null) {
            $query->where('provider_profile_id', (int) $providerId);
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(fn (Builder $inner) => $inner
                ->whereRaw('LOWER(reference) LIKE ?', [$term])
                ->orWhereHas('provider', fn (Builder $provider) => $provider->whereRaw('LOWER(business_name) LIKE ?', [$term])));
        }

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $page = $query->orderBy('created_at', $direction)->orderBy('id', $direction)->paginate(PageSize::from($filters));
        $this->countOtherClaims($page->getCollection());

        return $page;
    }

    /** The provider's own payment history, newest first. */
    public function historyFor(ProviderProfile $profile, array $filters): LengthAwarePaginator
    {
        return CommissionRemittance::query()
            ->where('provider_profile_id', $profile->id)
            ->withCount('bookings')
            ->latest()
            ->orderByDesc('id')
            ->paginate(PageSize::from($filters));
    }

    /** The provider's most recent payment, whatever became of it. */
    public function latestFor(int $providerProfileId): ?CommissionRemittance
    {
        return CommissionRemittance::query()
            ->where('provider_profile_id', $providerProfileId)
            ->withCount('bookings')
            ->latest()
            ->orderByDesc('id')
            ->first();
    }

    public function loadForAdmin(CommissionRemittance $remittance): CommissionRemittance
    {
        $remittance = $this->withAdminRelations(CommissionRemittance::query())->findOrFail($remittance->id);
        $this->countOtherClaims(collect([$remittance]));

        return $remittance;
    }

    /**
     * Sets `other_claims_count` on each remittance: how many *other* pending or
     * confirmed claims use the same GCash reference. A reviewer seeing one
     * knows two providers say they sent the same transfer, and checks the
     * sender before confirming. One query for the whole page.
     *
     * @param  Collection<int, CommissionRemittance>  $remittances
     */
    private function countOtherClaims(Collection $remittances): void
    {
        $live = [CommissionRemittance::PENDING, CommissionRemittance::CONFIRMED];

        $claims = CommissionRemittance::query()
            ->whereIn('reference', $remittances->pluck('reference')->unique()->values())
            ->whereIn('status', $live)
            ->groupBy('reference')
            ->selectRaw('reference, COUNT(*) AS claims')
            ->pluck('claims', 'reference');

        foreach ($remittances as $remittance) {
            $own = in_array($remittance->status, $live, true) ? 1 : 0;
            $remittance->setAttribute('other_claims_count', max(0, (int) ($claims[$remittance->reference] ?? 0) - $own));
        }
    }

    private function withAdminRelations(Builder $query): Builder
    {
        return $query->with([
            'provider:id,business_name,user_id,gcash_name,gcash_number',
            'provider.user:id,name,email',
            'bookings:id,booking_number,service_id,commission_status',
            'bookings.service:id,title',
            'reviewedBy:id,name',
        ]);
    }

    private function lockPending(CommissionRemittance $remittance): CommissionRemittance
    {
        $remittance = CommissionRemittance::query()->lockForUpdate()->findOrFail($remittance->id);

        if ($remittance->status !== CommissionRemittance::PENDING) {
            throw new ApiException(
                'This payment has already been reviewed.',
                409,
                errors: ['status' => ['This payment is '.$remittance->status.'.']],
            );
        }

        return $remittance;
    }

    private function pendingFor(int $providerProfileId): ?CommissionRemittance
    {
        return CommissionRemittance::query()
            ->where('provider_profile_id', $providerProfileId)
            ->where('status', CommissionRemittance::PENDING)
            ->first();
    }

    private function referenceConfirmed(string $reference): bool
    {
        return CommissionRemittance::query()
            ->where('reference', $reference)
            ->where('status', CommissionRemittance::CONFIRMED)
            ->exists();
    }

    private function alreadyPendingException(): ApiException
    {
        return new ApiException(
            'Your last payment is still waiting for confirmation.',
            409,
            errors: ['status' => ['Wait for SkillServe to confirm or reject it before submitting another.']],
        );
    }

    /** 422 when a provider submits it; 409 when a reviewer tries to confirm a second claim on it. */
    private function referenceConfirmedException(int $status = 422): ApiException
    {
        return new ApiException(
            'This GCash reference number has already been confirmed for a commission payment.',
            $status,
            errors: ['reference' => ['This reference number has already paid a commission.']],
        );
    }

    /** @return array{path: string, mime_type: string} */
    private function storeProof(ProviderProfile $profile, UploadedFile $proof): array
    {
        // A random name: the original filename is attacker-controlled and
        // must never determine where a file lands.
        $path = $proof->storeAs(
            (string) $profile->id,
            Str::uuid().'.'.$proof->extension(),
            ['disk' => self::DISK],
        );

        if ($path === false) {
            throw new ApiException('The receipt could not be saved. Please try again.', 500);
        }

        return ['path' => $path, 'mime_type' => $proof->getMimeType() ?? 'application/octet-stream'];
    }

    /**
     * Delete receipt screenshots of payments decided more than the retention
     * period ago (System Settings → Commission payments). The payment, its
     * reference and the decision are kept; only the image goes.
     *
     * @return int how many receipts were deleted
     */
    public function purgeExpiredReceipts(): int
    {
        $days = max(1, (int) $this->settings->value('commission_payments', 'receipt_retention_days'));
        $purged = 0;

        CommissionRemittance::query()
            ->where('status', '!=', CommissionRemittance::PENDING)
            ->whereNotNull('proof_path')
            ->where('reviewed_at', '<=', now()->subDays($days))
            ->chunkById(100, function ($remittances) use (&$purged): void {
                foreach ($remittances as $remittance) {
                    $this->deleteProof($remittance->proof_path);
                    $remittance->update(['proof_path' => null, 'proof_mime_type' => null]);
                    $purged++;
                }
            });

        return $purged;
    }

    private function deleteProof(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
