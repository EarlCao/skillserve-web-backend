<?php

namespace App\Modules\Bookings\Services;

use App\Models\User;
use App\Modules\Bookings\Actions\CancelBookingAction;
use App\Modules\Bookings\Events\BookingCancelled;
use App\Modules\Bookings\Events\BookingDisputeManaged;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Models\Booking;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

class BookingService extends BaseService
{
    private const SORTABLE = ['booking_number', 'created_at', 'total_price', 'status', 'scheduled_date'];

    public function __construct(
        private readonly CancelBookingAction $cancelBookingAction,
    ) {}

    /**
     * Paginated, searchable, filterable, sortable booking listing.
     *
     * @param  array{search?: string, status?: string, payment_status?: string, provider_id?: int, client_id?: int, service_id?: int, dispute_status?: string, date_from?: string, date_to?: string, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = Booking::query()
            ->with([
                'service:id,title,price,price_type,currency',
                'client:id,name,email',
                'provider:id,business_name',
                'provider.user:id,name',
            ]);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(booking_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(cancellation_reason) LIKE ?', [$term])
                    ->orWhereHas('client', function ($q2) use ($term): void {
                        $q2->whereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
                    })
                    ->orWhereHas('provider', function ($q2) use ($term): void {
                        $q2->whereRaw('LOWER(business_name) LIKE ?', [$term]);
                    })
                    ->orWhereHas('service', function ($q2) use ($term): void {
                        $q2->whereRaw('LOWER(title) LIKE ?', [$term]);
                    });
            });
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if ($paymentStatus = trim((string) ($filters['payment_status'] ?? ''))) {
            $query->where('payment_status', $paymentStatus);
        }

        if (! empty($filters['provider_id'])) {
            $query->where('provider_id', $filters['provider_id']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (! empty($filters['service_id'])) {
            $query->where('service_id', $filters['service_id']);
        }

        if ($disputeStatus = trim((string) ($filters['dispute_status'] ?? ''))) {
            $query->where('dispute_status', $disputeStatus);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters));
    }

    /**
     * Load a single booking with all relationships.
     */
    public function show(Booking $booking): Booking
    {
        return $booking->load([
            'service:id,title,description,price,price_type,currency,duration,location',
            'client:id,name,email',
            'provider:id,business_name',
            'provider.user:id,name,email',
            'cancelledByUser:id,name',
        ]);
    }

    /**
     * Load the booking status history from the activity log.
     *
     * @return array<int, array{id: int, event: string, logged_at: string, actor: array{id: int, name: string}|null, properties: array<string, mixed>}>
     */
    public function history(Booking $booking): array
    {
        return activity('bookings')
            ->where('subject_type', Booking::class)
            ->where('subject_id', $booking->id)
            ->latest()
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event,
                'logged_at' => $log->created_at?->toIso8601String(),
                'actor' => $log->causer ? ['id' => $log->causer->id, 'name' => $log->causer->name] : null,
                'properties' => $log->properties->toArray(),
            ])
            ->toArray();
    }

    /**
     * Cancel a booking when necessary.
     */
    public function cancel(Booking $booking, User $actor, ?string $reason = null): Booking
    {
        return $this->transaction(function () use ($booking, $actor, $reason): Booking {
            $oldStatus = $booking->status;

            $this->cancelBookingAction->handle($booking, $actor, $reason);

            $booking->load([
                'service:id,title,price,price_type,currency',
                'client:id,name,email',
                'provider:id,business_name',
                'provider.user:id,name',
                'cancelledByUser:id,name',
            ]);

            event(new BookingStatusChanged(
                booking: $booking,
                actor: $actor,
                oldStatus: $oldStatus,
                newStatus: 'cancelled',
            ));

            event(new BookingCancelled(booking: $booking, actor: $actor, reason: $reason));

            return $booking;
        });
    }

    /**
     * Manage a booking dispute (investigate, resolve, reject).
     */
    public function manageDispute(
        Booking $booking,
        User $actor,
        string $action,
        ?string $resolution = null,
    ): Booking {
        return $this->transaction(function () use ($booking, $actor, $action, $resolution): Booking {
            $updates = ['dispute_status' => $action === 'investigate' ? 'investigated' : $action];

            if ($action === 'resolve' && $resolution) {
                $updates['dispute_resolution'] = $resolution;
                $updates['status'] = 'completed';
            }

            $booking->update($updates);

            $booking->load([
                'service:id,title,price,price_type,currency',
                'client:id,name,email',
                'provider:id,business_name',
                'provider.user:id,name',
            ]);

            event(new BookingDisputeManaged(
                booking: $booking,
                actor: $actor,
                action: $action,
                resolution: $resolution,
            ));

            return $booking;
        });
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
