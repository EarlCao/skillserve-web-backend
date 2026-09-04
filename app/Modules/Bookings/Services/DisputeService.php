<?php

namespace App\Modules\Bookings\Services;

use App\Models\User;
use App\Modules\Bookings\Events\BookingDisputeManaged;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Models\Booking;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\Activitylog\Models\Activity;

/**
 * Coordinates the administrative dispute workflow while keeping the booking
 * module's existing booking endpoints backwards compatible.
 */
class DisputeService extends BaseService
{
    private const SORTABLE = ['booking_number', 'created_at', 'disputed_at', 'dispute_status'];

    public function index(array $filters): LengthAwarePaginator
    {
        $query = Booking::query()
            ->whereNotNull('dispute_reason')
            ->with($this->relations());

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->whereRaw('LOWER(booking_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(dispute_reason) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(dispute_resolution) LIKE ?', [$term])
                    ->orWhereHas('client', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(email) LIKE ?', [$term]))
                    ->orWhereHas('provider', fn ($q) => $q->whereRaw('LOWER(business_name) LIKE ?', [$term]))
                    ->orWhereHas('service', fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', [$term]));
            });
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('dispute_status', $status);
        }

        foreach (['provider_id', 'client_id', 'service_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('disputed_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('disputed_at', '<=', $filters['date_to']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'disputed_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->paginate($this->perPage($filters));
    }

    public function show(Booking $booking): Booking
    {
        $this->assertDispute($booking);

        return $booking->load($this->relations());
    }

    /**
     * Return the audit history specifically related to the dispute.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(Booking $booking): array
    {
        $this->assertDispute($booking);

        return Activity::query()
            ->where('log_name', 'bookings')
            ->where('subject_type', Booking::class)
            ->where('subject_id', $booking->id)
            ->where(function ($query): void {
                $query->where('description', 'booking_dispute_managed')
                    ->orWhere(function ($statusQuery): void {
                        $statusQuery->where('description', 'booking_status_changed')
                            ->whereJsonContains('properties->old_status', 'disputed');
                    });
            })
            ->with('causer:id,name')
            ->latest()
            ->get()
            ->map(fn ($log): array => [
                'id' => $log->id,
                'event' => $log->description,
                'logged_at' => $log->created_at?->toIso8601String(),
                'actor' => $log->causer ? ['id' => $log->causer->id, 'name' => $log->causer->name] : null,
                'properties' => $log->properties->toArray(),
            ])
            ->values()
            ->all();
    }

    public function investigate(Booking $booking, User $actor): Booking
    {
        return $this->changeStatus($booking, $actor, 'investigated');
    }

    public function addNote(Booking $booking, User $actor, string $note): Booking
    {
        return $this->transaction(function () use ($booking, $actor, $note): Booking {
            $booking = $this->lock($booking);
            $this->assertOpen($booking);
            $notes = $booking->dispute_notes ?? [];
            $notes[] = [
                'note' => trim($note),
                'created_at' => now()->toIso8601String(),
                'created_by' => $actor->id,
            ];
            $booking->update(['dispute_notes' => $notes]);
            $booking->load($this->relations());
            event(new BookingDisputeManaged(booking: $booking, actor: $actor, action: 'note_added', resolution: trim($note)));

            return $booking;
        });
    }

    public function resolve(Booking $booking, User $actor, string $resolution): Booking
    {
        return $this->transaction(function () use ($booking, $actor, $resolution): Booking {
            $booking = $this->lock($booking);
            $this->assertOpen($booking);
            $oldStatus = $booking->status;
            $booking->update([
                'dispute_status' => 'resolved',
                'dispute_resolution' => trim($resolution),
                'status' => 'completed',
            ]);
            $booking->load($this->relations());
            event(new BookingDisputeManaged(booking: $booking, actor: $actor, action: 'resolve', resolution: trim($resolution)));

            if ($oldStatus !== 'completed') {
                event(new BookingStatusChanged(booking: $booking, actor: $actor, oldStatus: $oldStatus, newStatus: 'completed'));
            }

            return $booking;
        });
    }

    public function reject(Booking $booking, User $actor, ?string $note = null): Booking
    {
        return $this->transaction(function () use ($booking, $actor, $note): Booking {
            $booking = $this->lock($booking);
            $this->assertOpen($booking);
            $booking->update([
                'dispute_status' => 'rejected',
                'dispute_resolution' => $note ? trim($note) : $booking->dispute_resolution,
            ]);
            $booking->load($this->relations());
            event(new BookingDisputeManaged(booking: $booking, actor: $actor, action: 'reject', resolution: $note ? trim($note) : null));

            return $booking;
        });
    }

    public function close(Booking $booking, User $actor, ?string $note = null): Booking
    {
        return $this->transaction(function () use ($booking, $actor, $note): Booking {
            $booking = $this->lock($booking);
            $this->assertDispute($booking);
            if ($booking->dispute_status !== 'resolved') {
                throw new ApiException('Only resolved disputes can be closed.', 422, errors: ['dispute_status' => ['Resolve the dispute before closing it.']]);
            }
            $updates = [
                'dispute_status' => 'closed',
                'dispute_closed_at' => now(),
                'dispute_closed_by' => $actor->id,
            ];
            if ($note) {
                $notes = $booking->dispute_notes ?? [];
                $notes[] = ['note' => trim($note), 'created_at' => now()->toIso8601String(), 'created_by' => $actor->id];
                $updates['dispute_notes'] = $notes;
            }
            $booking->update($updates);
            $booking->load($this->relations());
            event(new BookingDisputeManaged(booking: $booking, actor: $actor, action: 'close', resolution: $note ? trim($note) : null));

            return $booking;
        });
    }

    private function changeStatus(Booking $booking, User $actor, string $status): Booking
    {
        return $this->transaction(function () use ($booking, $actor, $status): Booking {
            $booking = $this->lock($booking);
            $this->assertOpen($booking);
            $booking->update(['dispute_status' => $status]);
            $booking->load($this->relations());
            event(new BookingDisputeManaged(booking: $booking, actor: $actor, action: 'investigate'));

            return $booking;
        });
    }

    private function lock(Booking $booking): Booking
    {
        return Booking::query()->lockForUpdate()->findOrFail($booking->id);
    }

    private function assertDispute(Booking $booking): void
    {
        if (! $booking->dispute_reason) {
            throw new ApiException('This booking has no dispute.', 422, errors: ['dispute' => ['The booking does not have a dispute to manage.']]);
        }
    }

    private function assertOpen(Booking $booking): void
    {
        $this->assertDispute($booking);
        if (in_array($booking->dispute_status, ['resolved', 'rejected', 'closed'], true)) {
            throw new ApiException('This dispute is no longer open.', 422, errors: ['dispute_status' => ['A resolved, rejected, or closed dispute cannot be changed.']]);
        }
    }

    private function relations(): array
    {
        return [
            'service:id,title,description,price,price_type,currency,duration,location',
            'client:id,name,email',
            'provider:id,business_name',
            'provider.user:id,name,email',
            'disputeClosedBy:id,name',
        ];
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
