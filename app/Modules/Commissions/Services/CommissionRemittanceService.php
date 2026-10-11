<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Events\CommissionRemittanceConfirmed;
use App\Modules\Commissions\Events\CommissionRemittanceRejected;
use App\Modules\Commissions\Models\CommissionRemittance;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use App\Shared\Helpers\PageSize;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Reviewing and settling providers' commission payments.
 *
 * Providers now pay only by PayMongo QR Ph ({@see CommissionQrPaymentService}).
 * Payments made the older way — GCash sent outside the app and the reference
 * typed in — are still reviewed here: an administrator finds the reference in
 * SkillServe's GCash history and confirms it, settling every booking it
 * covered, or rejects it with a reason.
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

    /** Receipts of decided payments are deleted this long after the decision. */
    public const RECEIPT_RETENTION_DAYS = 180;

    public function __construct(private readonly CommissionLedger $ledger) {}

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

        $this->settleCovered($remittance, $actor, $notes);

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
            // "Waiting for confirmation" means waiting for a person: a QR code
            // waiting to be scanned is PayMongo's to confirm, so it only
            // appears once something about it needs a reviewer.
            $status === CommissionRemittance::PENDING
                ? $query->needsReview()
                : $query->where('status', $status);
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
        $this->expireStaleQr($profile->id);

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
        $this->expireStaleQr($providerProfileId);

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

    /** The provider's one claim still open, of either method. */
    public function pendingFor(int $providerProfileId): ?CommissionRemittance
    {
        return CommissionRemittance::query()
            ->where('provider_profile_id', $providerProfileId)
            ->where('status', CommissionRemittance::PENDING)
            ->first();
    }

    /**
     * The bookings a payment made now covers, locked. Call inside a
     * transaction; 422 when nothing is owed.
     *
     * @return Collection<int, Booking>
     */
    public function lockOutstanding(int $providerProfileId): Collection
    {
        $bookings = $this->ledger->outstandingQuery($providerProfileId)
            ->lockForUpdate()
            ->get(['id', 'platform_fee']);

        if ($bookings->isEmpty()) {
            throw new ApiException(
                'You have no outstanding commission to pay.',
                422,
                errors: ['amount' => ['There is nothing to pay right now.']],
            );
        }

        return $bookings;
    }

    /** Fix which bookings [$remittance] pays for, and what each owed. */
    public function attachCovered(CommissionRemittance $remittance, Collection $bookings): void
    {
        $remittance->bookings()->attach(
            $bookings->mapWithKeys(fn ($booking): array => [
                $booking->id => ['amount' => $booking->platform_fee],
            ])->all(),
        );
    }

    /**
     * Settle every booking [$remittance] covers that is still outstanding.
     * One waived, voided or settled by hand meanwhile is skipped — the ledger
     * refuses to settle it twice. Call inside the transaction holding the
     * remittance's lock.
     */
    public function settleCovered(CommissionRemittance $remittance, User $actor, ?string $notes): void
    {
        $method = $remittance->isQr() ? 'qrph' : 'gcash';

        foreach ($remittance->bookings()->get() as $booking) {
            if ($booking->commission_status !== CommissionLedger::OUTSTANDING) {
                continue;
            }

            $this->ledger->settle($booking, $actor, $method, $remittance->reference, $notes, $remittance->id);
        }
    }

    /**
     * Stop waiting on QR codes nobody can pay any more, so they no longer
     * block a new attempt: one past its expiry becomes `expired`, and one
     * PayMongo never finished creating becomes `failed`.
     *
     * The expiry check allows two extra minutes, so a payment made in the
     * code's last moments is still confirmed by its webhook rather than racing
     * a fresh attempt for the same debt. A late `payment.paid` confirms an
     * expired claim anyway — the money arrived.
     */
    public function expireStaleQr(int $providerProfileId): void
    {
        $qr = fn () => CommissionRemittance::query()
            ->where('provider_profile_id', $providerProfileId)
            ->where('method', CommissionRemittance::METHOD_QRPH)
            ->where('status', CommissionRemittance::PENDING);

        $qr()->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subMinutes(2))
            ->update(['status' => CommissionRemittance::EXPIRED, 'qr_image' => null]);

        $qr()->whereNull('qr_image')
            ->where('created_at', '<', now()->subMinutes(5))
            ->update([
                'status' => CommissionRemittance::FAILED,
                'failure_reason' => 'The QR code was never created.',
            ]);
    }

    private function referenceConfirmed(string $reference): bool
    {
        return CommissionRemittance::query()
            ->where('reference', $reference)
            ->where('status', CommissionRemittance::CONFIRMED)
            ->exists();
    }

    /** A reviewer tried to confirm a second claim on a reference already confirmed. */
    private function referenceConfirmedException(int $status = 409): ApiException
    {
        return new ApiException(
            'This GCash reference number has already been confirmed for a commission payment.',
            $status,
            errors: ['reference' => ['This reference number has already paid a commission.']],
        );
    }

    /**
     * Delete receipt screenshots of payments decided more than
     * RECEIPT_RETENTION_DAYS ago. The payment, its reference and the decision
     * are kept; only the image goes.
     *
     * @return int how many receipts were deleted
     */
    public function purgeExpiredReceipts(): int
    {
        $days = self::RECEIPT_RETENTION_DAYS;
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
