<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\Bookings\Enums\PaymentMethod;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Events\CommissionSettled;
use App\Modules\Commissions\Events\CommissionWaived;
use App\Modules\Commissions\Models\CommissionSettlement;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Shared\Exceptions\ApiException;
use App\Shared\Helpers\PageSize;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Whether SkillServe has been paid its share of each booking.
 *
 * The commission is inclusive, so on an on-hand job the provider collects the
 * whole advertised price and owes the platform's share back:
 *
 *   pending ──customer pays──▶ outstanding ──remittance recorded──▶ settled
 *      │                            └──────administrator waives───▶ waived
 *      └── booking cancelled or fully refunded ─────────────────────▶ voided
 *
 * Every write re-reads the booking under a row lock, so a settlement and a
 * refund landing together cannot both win.
 */
class CommissionLedger extends BaseService
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /** Nothing is owed yet, but it still could be. */
    public const PENDING = 'pending';

    /** The provider holds SkillServe's share and must remit it. */
    public const OUTSTANDING = 'outstanding';

    public const SETTLED = 'settled';

    public const WAIVED = 'waived';

    /** The booking fell through; no share is due. */
    public const VOIDED = 'voided';

    /**
     * The customer has paid, so the provider now holds SkillServe's share.
     * A booking carrying no commission settles itself — there is nothing to
     * chase, and leaving it outstanding would block the provider over ₱0.
     */
    public function markOutstanding(Booking $booking): void
    {
        $this->transaction(function () use ($booking): void {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->commission_status !== self::PENDING) {
                return;
            }

            $owed = round((float) $booking->platform_fee, 2);

            // Nothing to chase, so it settles itself rather than blocking the
            // provider over ₱0.
            if ($owed <= 0 || $this->platformCollected($booking)) {
                $booking->update([
                    'commission_status' => self::SETTLED,
                    'commission_settled_at' => now(),
                ]);

                return;
            }

            $booking->update(['commission_status' => self::OUTSTANDING]);
        });
    }

    /**
     * Whether SkillServe's share reached it directly instead of passing
     * through the provider's hands.
     *
     * Asked of the gateway rather than the payment method, so that switching
     * GCash to PayMongo in config/payments.php makes those commissions settle
     * on payment without this class changing. Today every gateway is manual,
     * so this is always false.
     */
    private function platformCollected(Booking $booking): bool
    {
        $method = PaymentMethod::fromInput($booking->payment_method);

        return $method !== null && $this->gateways->for($method)->collectsPayment();
    }

    /**
     * The booking fell through. A commission already settled is left alone —
     * the money changed hands, and reversing it is a refund decision, not a
     * status flip.
     */
    public function void(Booking $booking): void
    {
        $this->transaction(function () use ($booking): void {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! in_array($booking->commission_status, [self::PENDING, self::OUTSTANDING], true)) {
                return;
            }

            $booking->update(['commission_status' => self::VOIDED]);
        });
    }

    /**
     * Record that the provider remitted SkillServe's share of [$booking].
     */
    public function settle(
        Booking $booking,
        User $actor,
        string $method,
        ?string $reference = null,
        ?string $notes = null,
    ): CommissionSettlement {
        return $this->transaction(function () use ($booking, $actor, $method, $reference, $notes): CommissionSettlement {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->commission_status !== self::OUTSTANDING) {
                throw new ApiException(
                    'This commission is not outstanding.',
                    409,
                    errors: ['commission_status' => ['The commission is '.$booking->commission_status.'.']],
                );
            }

            $settlement = CommissionSettlement::create([
                'booking_id' => $booking->id,
                'provider_profile_id' => $booking->provider_id,
                'amount' => $booking->platform_fee,
                'method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'settled_by' => $actor->id,
                'settled_at' => now(),
            ]);

            $booking->update([
                'commission_status' => self::SETTLED,
                'commission_settled_at' => $settlement->settled_at,
            ]);

            event(new CommissionSettled(booking: $booking, settlement: $settlement, actor: $actor));

            return $settlement;
        });
    }

    /** Write the debt off, with a reason for the audit trail. */
    public function waive(Booking $booking, User $actor, string $reason): Booking
    {
        return $this->transaction(function () use ($booking, $actor, $reason): Booking {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->commission_status !== self::OUTSTANDING) {
                throw new ApiException(
                    'This commission is not outstanding.',
                    409,
                    errors: ['commission_status' => ['The commission is '.$booking->commission_status.'.']],
                );
            }

            $booking->update([
                'commission_status' => self::WAIVED,
                'commission_settled_at' => now(),
            ]);

            event(new CommissionWaived(booking: $booking, actor: $actor, reason: $reason));

            return $booking;
        });
    }

    /**
     * Every booking's commission, for the administrator's ledger view.
     *
     * @param  array{status?: string, provider_id?: int, search?: string, from?: string, to?: string, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = Booking::query()
            ->whereNotNull('platform_fee')
            ->with([
                'provider:id,business_name,user_id',
                'service:id,title',
                'client:id,name',
                'commissionSettlement.settledBy:id,name',
            ]);

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('commission_status', $status);
        }

        if ($providerId = $filters['provider_id'] ?? null) {
            $query->where('provider_id', (int) $providerId);
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->whereRaw('LOWER(booking_number) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        // Filtered on when the money was taken, which is what a reconciliation
        // period means here.
        if ($from = $filters['from'] ?? null) {
            $query->whereDate('paid_at', '>=', $from);
        }

        if ($to = $filters['to'] ?? null) {
            $query->whereDate('paid_at', '<=', $to);
        }

        $sort = in_array($filters['sort'] ?? null, ['paid_at', 'platform_fee', 'created_at'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('id')->paginate(PageSize::from($filters));
    }

    /**
     * Totals for the ledger header, over the same filters as index().
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public function totals(array $filters): array
    {
        $base = fn (): Builder => Booking::query()
            ->when($filters['provider_id'] ?? null, fn ($q, $id) => $q->where('provider_id', (int) $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('paid_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('paid_at', '<=', $to));

        $sumOf = fn (string $status): string => number_format(
            (float) $base()->where('commission_status', $status)->sum('platform_fee'),
            2, '.', '',
        );

        return [
            'outstanding' => $sumOf(self::OUTSTANDING),
            'settled' => $sumOf(self::SETTLED),
            'waived' => $sumOf(self::WAIVED),
        ];
    }

    /** Bookings on which [$providerProfileId] still owes SkillServe. */
    public function outstandingQuery(int $providerProfileId)
    {
        return Booking::query()
            ->where('provider_id', $providerProfileId)
            ->where('commission_status', self::OUTSTANDING);
    }

    /**
     * What [$providerProfileId] owes in total, and across how many bookings.
     *
     * @return array{total: float, count: int}
     */
    public function outstandingFor(int $providerProfileId): array
    {
        $query = $this->outstandingQuery($providerProfileId);

        return [
            'total' => round((float) (clone $query)->sum('platform_fee'), 2),
            'count' => (clone $query)->count(),
        ];
    }
}
