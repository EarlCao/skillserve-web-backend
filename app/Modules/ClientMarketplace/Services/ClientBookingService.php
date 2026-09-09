<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Models\User;
use App\Modules\Bookings\Events\BookingCancelled;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientMarketplace\Actions\CancelClientBookingAction;
use App\Modules\ClientMarketplace\Actions\CreateClientBookingAction;
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
                ProviderProfile::query()
                    ->whereKey($service->provider_id)
                    ->lockForUpdate()
                    ->firstOrFail();

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

    private function assertSameIdempotentRequest(Booking $booking, array $data, ?Carbon $scheduledEnd = null): void
    {
        $requestedDate = Carbon::parse($data['scheduled_date']);
        if ((int) $booking->service_id !== (int) $data['service_id']
            || abs($booking->scheduled_date?->getTimestamp() - $requestedDate->getTimestamp()) > 1
            || ($scheduledEnd !== null && ($booking->scheduled_end_date === null
                || abs($booking->scheduled_end_date->getTimestamp() - $scheduledEnd->getTimestamp()) > 1))
            || $booking->client_notes !== ($data['client_notes'] ?? null)
            || $booking->payment_method !== ($data['payment_method'] ?? null)) {
            throw new ApiException(
                'This idempotency key was already used for a different booking request.',
                409,
            );
        }
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

    private function assertNoOverlap(int $providerId, string $scheduledDate, Carbon $scheduledEnd): void
    {
        $start = Carbon::parse($scheduledDate);
        $bookings = Booking::query()
            ->where('provider_id', $providerId)
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
