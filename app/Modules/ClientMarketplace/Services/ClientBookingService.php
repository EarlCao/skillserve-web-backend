<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Models\User;
use App\Modules\Bookings\Events\BookingCancelled;
use App\Modules\Bookings\Events\BookingRescheduled;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientMarketplace\Actions\CancelClientBookingAction;
use App\Modules\ClientMarketplace\Actions\CreateClientBookingAction;
use App\Modules\Providers\Models\ProviderAvailability;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Services\Models\Service;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;

class ClientBookingService extends BaseService
{
    public function __construct(
        private readonly ClientCatalogService $catalogService,
        private readonly CreateClientBookingAction $createBookingAction,
        private readonly CancelClientBookingAction $cancelBookingAction,
    ) {}

    public function index(User $client, array $filters): LengthAwarePaginator
    {
        $query = Booking::query()
            ->where('client_id', $client->id)
            ->with($this->bookingRelations());

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'scheduled_date', 'status', 'total_price'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->paginate($this->perPage($filters));
    }

    public function show(User $client, Booking $booking): Booking
    {
        return Booking::query()
            ->where('client_id', $client->id)
            ->whereKey($booking->id)
            ->with($this->bookingRelations())
            ->firstOrFail();
    }

    public function create(User $client, array $data, ?string $idempotencyKey): Booking
    {
        try {
            $booking = $this->transaction(function () use ($client, $data, $idempotencyKey): Booking {
                $service = $this->catalogService->bookableService((int) $data['service_id']);
                $scheduledEnd = $this->scheduledEnd($service, $data);

                if ($idempotencyKey) {
                    $existing = Booking::query()
                        ->where('client_id', $client->id)
                        ->where('client_idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $this->assertSameIdempotentRequest($existing, $data, $scheduledEnd);

                        return $existing->load($this->bookingRelations());
                    }
                }

                // Lock the provider row so two concurrent requests cannot both
                // pass the overlap check before either booking is inserted.
                $provider = ProviderProfile::query()
                    ->whereKey($service->provider_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertAcceptingBookings($provider);
                $this->assertWithinProviderHours($provider, $data['scheduled_date'], $scheduledEnd);
                $this->assertNoOverlap($service->provider_id, $data['scheduled_date'], $scheduledEnd);

                $booking = $this->createBookingAction->handle(
                    $client,
                    $service,
                    [...$data, 'scheduled_end_date' => $scheduledEnd],
                    $idempotencyKey,
                );

                return $booking->load($this->bookingRelations());
            });
        } catch (QueryException $exception) {
            if (! $idempotencyKey) {
                throw $exception;
            }

            $existing = Booking::query()
                ->where('client_id', $client->id)
                ->where('client_idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            $service = $this->catalogService->bookableService((int) $data['service_id']);
            $this->assertSameIdempotentRequest($existing, $data, $this->scheduledEnd($service, $data));
            $booking = $existing->load($this->bookingRelations());
        }

        return $booking;
    }

    public function cancel(User $client, Booking $booking, ?string $reason): Booking
    {
        return $this->transaction(function () use ($client, $booking, $reason): Booking {
            $booking = Booking::query()
                ->where('client_id', $client->id)
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $booking->isCancellable()) {
                throw new ApiException('This booking cannot be cancelled in its current status.', 422);
            }

            $oldStatus = $booking->status;
            $booking = $this->cancelBookingAction->handle($booking, $client, $reason);
            $booking->load($this->bookingRelations());

            event(new BookingStatusChanged(
                booking: $booking,
                actor: $client,
                oldStatus: $oldStatus,
                newStatus: 'cancelled',
            ));
            event(new BookingCancelled(booking: $booking, actor: $client, reason: $reason));

            return $booking;
        });
    }

    /**
     * Move a pending or confirmed booking to a new time. The new window must
     * pass the same hours and overlap checks as a new booking; an accepted
     * booking goes back to pending, because the provider agreed to the old
     * time, not this one.
     *
     * Pausing new bookings does not block a reschedule: it is existing work,
     * and the provider can still decline the new time.
     */
    public function reschedule(User $client, Booking $booking, array $data): Booking
    {
        return $this->transaction(function () use ($client, $booking, $data): Booking {
            $booking = Booking::query()
                ->where('client_id', $client->id)
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $booking->isReschedulable()) {
                throw new ApiException(
                    'This booking can no longer be rescheduled.',
                    422,
                    errors: ['status' => ['This booking is '.$booking->status.'; only pending or confirmed bookings can be rescheduled.']],
                );
            }

            $scheduledEnd = $this->rescheduledEnd($booking, $data);

            if ($booking->scheduled_date?->equalTo(Carbon::parse($data['scheduled_date']))
                && $booking->scheduled_end_date?->equalTo($scheduledEnd)) {
                throw new ApiException(
                    'Choose a different time to reschedule this booking.',
                    422,
                    errors: ['scheduled_date' => ['The booking is already scheduled for this time.']],
                );
            }

            // Same provider lock as create(), so a reschedule and a new
            // booking cannot both claim the same window.
            $provider = ProviderProfile::query()
                ->whereKey($booking->provider_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertWithinProviderHours($provider, $data['scheduled_date'], $scheduledEnd);
            $this->assertNoOverlap($booking->provider_id, $data['scheduled_date'], $scheduledEnd, $booking->id);

            $oldStatus = $booking->status;
            $previousStart = $booking->scheduled_date;
            $previousEnd = $booking->scheduled_end_date;

            $booking->update([
                'scheduled_date' => $data['scheduled_date'],
                'scheduled_end_date' => $scheduledEnd,
                'rescheduled_at' => now(),
                'status' => 'pending',
                'confirmed_at' => null,
            ]);
            $booking = $booking->fresh()->load($this->bookingRelations());

            if ($oldStatus !== 'pending') {
                event(new BookingStatusChanged(
                    booking: $booking,
                    actor: $client,
                    oldStatus: $oldStatus,
                    newStatus: 'pending',
                ));
            }
            event(new BookingRescheduled(
                booking: $booking,
                actor: $client,
                previousStart: $previousStart,
                previousEnd: $previousEnd,
            ));

            return $booking;
        });
    }

    private function assertSameIdempotentRequest(Booking $booking, array $data, ?Carbon $scheduledEnd = null): void
    {
        $requestedDate = Carbon::parse($data['scheduled_date']);
        if ((int) $booking->service_id !== (int) $data['service_id']
            || abs($booking->scheduled_date?->getTimestamp() - $requestedDate->getTimestamp()) > 1
            || ($scheduledEnd !== null && ($booking->scheduled_end_date === null
                || abs($booking->scheduled_end_date->getTimestamp() - $scheduledEnd->getTimestamp()) > 1))
            || $booking->client_notes !== ($data['client_notes'] ?? null)
            || $booking->service_address !== ($data['service_address'] ?? null)
            || $booking->contact_phone !== ($data['contact_phone'] ?? null)
            || $booking->payment_method !== ($data['payment_method'] ?? null)) {
            throw new ApiException(
                'This idempotency key was already used for a different booking request.',
                409,
            );
        }
    }

    /**
     * An explicit end wins; otherwise the booking keeps its current length,
     * falling back to the service duration for a booking recorded without one.
     */
    private function rescheduledEnd(Booking $booking, array $data): Carbon
    {
        if (empty($data['scheduled_end_date']) && $booking->scheduled_date && $booking->scheduled_end_date) {
            return Carbon::parse($data['scheduled_date'])
                ->addSeconds((int) $booking->scheduled_date->diffInSeconds($booking->scheduled_end_date));
        }

        return $this->scheduledEnd(Service::withTrashed()->findOrFail($booking->service_id), $data);
    }

    private function scheduledEnd(Service $service, array $data): Carbon
    {
        if (! empty($data['scheduled_end_date'])) {
            return Carbon::parse($data['scheduled_end_date']);
        }

        $duration = (string) ($service->duration ?? '');
        preg_match_all('/(\d+(?:\.\d+)?)\s*(minute|hour|day)s?/i', $duration, $matches);

        if ($matches[1] === []) {
            // Services with display-only durations still get a conservative,
            // deterministic one-hour booking window.
            return Carbon::parse($data['scheduled_date'])->addHour();
        }

        $amount = max(array_map('floatval', $matches[1]));
        $unit = strtolower(end($matches[2]));
        $start = Carbon::parse($data['scheduled_date']);

        return match ($unit) {
            'minute' => $start->addMinutes((int) ceil($amount)),
            'day' => $start->addDays((int) ceil($amount)),
            default => $start->addMinutes((int) ceil($amount * 60)),
        };
    }

    private function assertAcceptingBookings(ProviderProfile $provider): void
    {
        if (! $provider->is_accepting_bookings) {
            throw new ApiException(
                'This provider is not accepting new bookings right now.',
                422,
                errors: ['service_id' => ['The provider is not accepting new bookings.']],
            );
        }
    }

    /**
     * When the provider publishes weekly hours, the whole booking must fall
     * inside that day's window.
     *
     * A provider with no published hours is unconstrained, which is how
     * every provider behaved before schedules existed. Times are compared
     * as wall clock, the same basis `scheduled_date` is recorded in.
     */
    private function assertWithinProviderHours(ProviderProfile $provider, string $scheduledDate, Carbon $scheduledEnd): void
    {
        $schedule = $provider->availabilities()->get();

        if ($schedule->isEmpty()) {
            return;
        }

        $start = Carbon::parse($scheduledDate);
        $window = $schedule->firstWhere('day_of_week', $start->dayOfWeek);

        if ($window === null) {
            throw new ApiException(
                'The provider does not work on the requested day.',
                422,
                errors: ['scheduled_date' => [
                    'The provider does not publish hours on '.ProviderAvailability::DAYS[$start->dayOfWeek].'s.',
                ]],
            );
        }

        $startMinutes = ($start->hour * 60) + $start->minute;
        // Measured from the start of the booking's day, so a booking that
        // runs past midnight lands beyond the window end and is refused.
        $endMinutes = (int) $start->copy()->startOfDay()->diffInMinutes($scheduledEnd, false);

        if ($startMinutes < ProviderAvailability::minutes($window->start_time)
            || $endMinutes > ProviderAvailability::minutes($window->end_time)) {
            throw new ApiException(
                'The provider is not available at the requested time.',
                422,
                errors: ['scheduled_date' => [
                    'The provider works '.$window->dayName().'s from '.$window->startsAt().' to '.$window->endsAt().'.',
                ]],
            );
        }
    }

    private function assertNoOverlap(int $providerId, string $scheduledDate, Carbon $scheduledEnd, ?int $ignoreBookingId = null): void
    {
        $start = Carbon::parse($scheduledDate);
        $bookings = Booking::query()
            ->where('provider_id', $providerId)
            ->when($ignoreBookingId, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->whereIn('status', ['pending', 'confirmed', 'active'])
            ->whereNotNull('scheduled_date')
            ->where('scheduled_date', '<', $scheduledEnd)
            ->get(['scheduled_date', 'scheduled_end_date']);

        $overlap = $bookings->contains(function (Booking $booking) use ($start, $scheduledEnd): bool {
            $existingStart = $booking->scheduled_date;
            $existingEnd = $booking->scheduled_end_date ?? $existingStart?->copy()->addHour();

            return $existingStart !== null
                && $existingEnd !== null
                && $existingStart->lt($scheduledEnd)
                && $existingEnd->gt($start);
        });

        if ($overlap) {
            throw new ApiException(
                'The provider is already booked during the requested time window.',
                409,
                errors: ['scheduled_date' => ['The requested time overlaps another booking for this provider.']],
            );
        }
    }

    private function bookingRelations(): array
    {
        return [
            'service:id,provider_id,category_id,subcategory_id,title,description,price,price_type,currency,duration,location,average_rating,total_reviews,total_bookings',
            'service.category:id,name',
            'service.subcategory:id,name',
            'service.provider:id,business_name,average_rating,total_reviews',
            'provider:id,business_name,average_rating,total_reviews',
            'review:id,booking_id,reviewer_id,provider_id,service_id,rating,comment,status,created_at,updated_at',
        ];
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
