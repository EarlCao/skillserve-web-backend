<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Models\User;
use App\Modules\Bookings\Events\BookingCancelled;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The jobs side of a booking: the provider reads the bookings placed with
 * them and moves each one along its lifecycle.
 *
 * pending ──confirm──▶ confirmed ──start──▶ active ──complete──▶ completed
 *    └──────decline──▶ cancelled
 *
 * Every transition re-reads the booking under a row lock, so two taps (or a
 * client cancelling at the same moment) cannot both win.
 */
class ProviderBookingService extends BaseService
{
    /** The status each action requires, and the status it writes. */
    private const TRANSITIONS = [
        'confirm' => ['from' => ['pending'], 'to' => 'confirmed', 'timestamp' => 'confirmed_at'],
        'decline' => ['from' => ['pending'], 'to' => 'cancelled', 'timestamp' => 'cancelled_at'],
        'start' => ['from' => ['confirmed'], 'to' => 'active', 'timestamp' => 'started_at'],
        'complete' => ['from' => ['active'], 'to' => 'completed', 'timestamp' => 'completed_at'],
    ];

    private const REFUSALS = [
        'confirm' => ['Only a pending booking can be accepted.', 'accepted'],
        'decline' => ['Only a pending booking can be declined.', 'declined'],
        'start' => ['Only a confirmed booking can be started.', 'started'],
        'complete' => ['Only a job in progress can be completed.', 'completed'],
    ];

    public function index(User $providerUser, array $filters): LengthAwarePaginator
    {
        $query = Booking::query()
            ->where('provider_id', $this->profile($providerUser)->id)
            ->with($this->bookingRelations());

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'scheduled_date', 'status', 'total_price'], true)
            ? $filters['sort']
            : 'scheduled_date';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->paginate($this->perPage($filters));
    }

    public function show(User $providerUser, Booking $booking): Booking
    {
        return Booking::query()
            ->where('provider_id', $this->profile($providerUser)->id)
            ->whereKey($booking->id)
            ->with($this->bookingRelations())
            ->firstOrFail();
    }

    public function confirm(User $providerUser, Booking $booking): Booking
    {
        return $this->transition($providerUser, $booking, 'confirm');
    }

    public function decline(User $providerUser, Booking $booking, ?string $reason): Booking
    {
        return $this->transition($providerUser, $booking, 'decline', $reason);
    }

    public function start(User $providerUser, Booking $booking): Booking
    {
        return $this->transition($providerUser, $booking, 'start');
    }

    public function complete(User $providerUser, Booking $booking): Booking
    {
        return $this->transition($providerUser, $booking, 'complete');
    }

    private function transition(User $providerUser, Booking $booking, string $action, ?string $reason = null): Booking
    {
        $profileId = $this->profile($providerUser)->id;
        $rules = self::TRANSITIONS[$action];

        return $this->transaction(function () use ($providerUser, $booking, $action, $reason, $profileId, $rules): Booking {
            $booking = Booking::query()
                ->where('provider_id', $profileId)
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($booking->status, $rules['from'], true)) {
                [$message, $pastTense] = self::REFUSALS[$action];

                throw new ApiException(
                    $message,
                    422,
                    errors: ['status' => ['This booking is '.$booking->status.' and can no longer be '.$pastTense.'.']],
                );
            }

            $oldStatus = $booking->status;
            $updates = [
                'status' => $rules['to'],
                $rules['timestamp'] => now(),
            ];

            if ($action === 'decline') {
                $updates['cancellation_reason'] = $reason;
                $updates['cancelled_by'] = $providerUser->id;
            }

            $booking->update($updates);
            $booking = $booking->fresh()->load($this->bookingRelations());

            event(new BookingStatusChanged(
                booking: $booking,
                actor: $providerUser,
                oldStatus: $oldStatus,
                newStatus: $rules['to'],
            ));

            if ($action === 'decline') {
                event(new BookingCancelled(booking: $booking, actor: $providerUser, reason: $reason));
            }

            return $booking;
        });
    }

    private function profile(User $providerUser): ProviderProfile
    {
        return $providerUser->providerProfile
            ?? throw new ApiException('Provider profile not found.', 404);
    }

    private function bookingRelations(): array
    {
        return [
            'service:id,provider_id,category_id,subcategory_id,title,description,price,price_type,currency,duration,location,average_rating,total_reviews,total_bookings',
            'service.category:id,name',
            'service.subcategory:id,name',
            'service.provider:id,business_name,average_rating,total_reviews',
            'client:id,name,phone,profile_photo_path',
            'review:id,booking_id,reviewer_id,provider_id,service_id,rating,comment,status,created_at,updated_at',
        ];
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
